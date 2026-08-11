<?php

namespace Tests\Feature\Market;

use App\Enums\AnalysisStatus;
use App\Jobs\RunMarketWeightAnalysis;
use App\Models\LlmProviderSetting;
use App\Models\MarketWeightAnalysis;
use App\Models\User;
use App\Services\Analysis\MarketWeightAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarketWeightAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private const LLM_ENDPOINT = 'http://localhost:11434/v1/chat/completions';

    private function makeSetting(User $user): LlmProviderSetting
    {
        return $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Local Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);
    }

    public function test_store_returns_pending_row_and_dispatches_without_calling_the_llm(): void
    {
        Queue::fake();
        // request 內不該有任何外送 HTTP（LLM／行情都該在 job 裡）。
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $setting = $this->makeSetting($user);

        $this->actingAs($user)
            ->post('/market/weight-analysis')
            ->assertRedirect('/market/weight-analysis');

        $analysis = MarketWeightAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Pending, $analysis->status);
        $this->assertSame('pending', $analysis->provider_type);
        $this->assertSame($user->id, $analysis->user_id);

        Queue::assertPushed(RunMarketWeightAnalysis::class, 1);
        $this->assertSame($setting->id, $user->defaultLlmSetting()?->id);
    }

    public function test_store_is_blocked_without_an_llm_setting(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/market/weight-analysis')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, MarketWeightAnalysis::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_show_page_renders_with_basket_summary(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);

        $props = $this->actingAs($user)
            ->get('/market/weight-analysis')
            ->assertOk()
            ->viewData('page')['props'];

        $limit = (int) config('weight_basket.limit', 15);
        $this->assertSame($limit, $props['basketSummary']['count']);
        $this->assertNotEmpty($props['basketSummary']['weights_as_of']);
        // 台積電為最大權值，必在籃子預覽中。
        $symbols = array_column($props['basketSummary']['symbols'], 'symbol');
        $this->assertContains('2330.TW', $symbols);
    }

    public function test_queued_job_fills_the_report_from_the_llm(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);

        Http::fake([
            self::LLM_ENDPOINT => Http::response([
                'model' => 'llama3.1',
                'choices' => [['message' => [
                    'content' => '{"summary":"# 大盤結論\n權值撐盤，中性偏多","points":["台積電站上均線"],"symbols":["2330.TW"]}',
                ]]],
            ], 200),
        ]);

        // sync driver：dispatch 當下就跑完，等同 worker 取出執行。
        $this->actingAs($user)->post('/market/weight-analysis');

        $analysis = MarketWeightAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Completed, $analysis->status);
        $this->assertSame('ollama', $analysis->provider_type);
        $this->assertStringContainsString('大盤結論', (string) $analysis->summary);
        $this->assertSame(['2330.TW'], $analysis->related_symbols);
        // 資料層必須存在：逐檔、聚合、對照標的都算得出來。
        $this->assertNotEmpty($analysis->payload['stocks']);
        $this->assertTrue($analysis->payload['stocks'][0]['available']);
        $this->assertNotNull($analysis->payload['aggregate']['weighted_change_percent']);
        $this->assertNotEmpty($analysis->payload['benchmarks']);
    }

    public function test_symbols_outside_the_basket_are_dropped(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);

        Http::fake([
            self::LLM_ENDPOINT => Http::response([
                'model' => 'llama3.1',
                // 模型多吐了一個不在籃子裡的 AAPL，須被過濾。
                'choices' => [['message' => ['content' => '{"summary":"x","points":[],"symbols":["2330.TW","AAPL"]}']]],
            ], 200),
        ]);

        $this->actingAs($user)->post('/market/weight-analysis');

        $this->assertSame(['2330.TW'], MarketWeightAnalysis::query()->sole()->related_symbols);
    }

    public function test_job_marks_failed_but_keeps_the_data_layer_when_the_llm_errors(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);

        Http::fake([
            self::LLM_ENDPOINT => Http::response([], 500),
        ]);

        $this->actingAs($user)->post('/market/weight-analysis');

        $analysis = MarketWeightAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Failed, $analysis->status);
        $this->assertSame('error', $analysis->provider_type);
        // AI 失敗不得丟掉資料層。
        $this->assertNotEmpty($analysis->payload['stocks']);
        $this->assertNotNull($analysis->payload['aggregate']);
    }

    public function test_service_degrades_to_a_rule_summary_without_a_setting(): void
    {
        Http::preventStrayRequests();

        $result = app(MarketWeightAnalysisService::class)->analyze(null, 'reference-model');

        $this->assertSame('none', $result['provider']);
        $this->assertStringContainsString('未經 AI 分析', $result['summary']);
        $this->assertNotEmpty($result['payload']['stocks']);

        // 聚合：fake 行情每檔漲跌相同，加權平均等於單檔漲跌；涵蓋全部籃子檔數。
        $aggregate = $result['payload']['aggregate'];
        $limit = (int) config('weight_basket.limit', 15);
        $this->assertSame($limit, $aggregate['total']);
        $this->assertSame($limit, $aggregate['covered']);
        $this->assertNotNull($aggregate['weighted_change_percent']);
        // 台股籃子每檔都有三大法人資料，外資聚合不應為 null。
        $this->assertNotNull($aggregate['foreign_net_sum']);
    }

    public function test_benchmarks_include_index_and_representative_etfs(): void
    {
        Http::preventStrayRequests();

        $result = app(MarketWeightAnalysisService::class)->analyze(null, 'reference-model');

        $symbols = array_column($result['payload']['benchmarks'], 'symbol');
        $this->assertContains('^TWII', $symbols);
        $this->assertContains('0050.TW', $symbols);
        $this->assertContains('0056.TW', $symbols);
    }
}
