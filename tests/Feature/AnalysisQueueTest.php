<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Jobs\RunNewsDailySummary;
use App\Jobs\RunNewsItemAnalysis;
use App\Jobs\RunStockAnalysis;
use App\Models\Instrument;
use App\Models\LlmProviderSetting;
use App\Models\NewsAnalysis;
use App\Models\NewsItem;
use App\Models\StockAnalysis;
use App\Models\User;
use App\Services\Llm\LlmProviderFactory;
use App\Services\News\NewsAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 分析改為佇列執行的行為。
 *
 * 重點不是「有沒有分析結果」（原有測試已涵蓋，且測試環境 queue driver 是 sync，
 * job 會就地跑完），而是 request 不再等待 LLM：controller 必須先落地一筆
 * pending 就回應，實際運算交給 job。
 */
class AnalysisQueueTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeNewsItem(): NewsItem
    {
        return NewsItem::create([
            'source' => 'CNBC',
            'title' => 'headline',
            'summary' => 'summary',
            'url' => 'https://news.test/'.uniqid('', true),
            'url_hash' => sha1(uniqid('', true)),
            'published_at' => now(),
            'language' => 'en',
            'market' => 'US',
            'topic' => 'macro',
            'domain' => 'tech',
            'relevant' => true,
            'related_symbols' => ['NVDA'],
        ]);
    }

    public function test_stock_analysis_request_returns_pending_row_without_calling_the_llm(): void
    {
        Queue::fake();
        // 任何外送 HTTP 都算失敗：request 內不該有 LLM 或行情呼叫。
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $setting = $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL']);

        $this->actingAs($user)
            ->post("/stocks/{$instrument->id}/analyses")
            ->assertRedirect();

        $analysis = StockAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Pending, $analysis->status);
        $this->assertSame('pending', $analysis->provider_type);
        $this->assertSame([], $analysis->rule_signal);

        Queue::assertPushed(RunStockAnalysis::class, 1);
        $this->assertSame($setting->id, $user->defaultLlmSetting()?->id);
    }

    public function test_queued_stock_job_fills_in_the_result(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL']);

        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response([
                'model' => 'llama3.1',
                'choices' => [['message' => ['content' => '分析內容']]],
            ], 200),
        ]);

        // sync driver：dispatch 當下就跑完，等同於 worker 取出執行。
        $this->actingAs($user)->post("/stocks/{$instrument->id}/analyses");

        $analysis = StockAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Completed, $analysis->status);
        $this->assertSame('ollama', $analysis->provider_type);
        $this->assertSame('分析內容', $analysis->llm_output['content']);
        $this->assertNotSame([], $analysis->rule_signal);
    }

    public function test_stock_job_marks_failed_when_the_provider_errors(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL']);

        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response([], 500),
        ]);

        $this->actingAs($user)->post("/stocks/{$instrument->id}/analyses");

        $analysis = StockAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Failed, $analysis->status);
        // 規則訊號是本地計算，AI 失敗不應該把它一起丟掉。
        $this->assertNotSame([], $analysis->rule_signal);
    }

    public function test_stock_analysis_without_any_provider_still_completes(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL']);

        $this->actingAs($user)->post("/stocks/{$instrument->id}/analyses");

        $analysis = StockAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Completed, $analysis->status);
        $this->assertSame('none', $analysis->provider_type);
    }

    public function test_news_item_analysis_request_returns_pending_row_without_calling_the_llm(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $this->makeSetting($user);
        $item = $this->makeNewsItem();

        $this->actingAs($user)
            ->post("/news/{$item->id}/analyses")
            ->assertRedirect();

        $analysis = NewsAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Pending, $analysis->status);
        $this->assertNull($analysis->summary);

        Queue::assertPushed(RunNewsItemAnalysis::class, 1);
    }

    public function test_daily_summary_request_returns_pending_row_without_calling_the_llm(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $this->makeSetting($user);
        $this->makeNewsItem();

        $this->actingAs($user)
            ->post('/news/daily-summary')
            ->assertRedirect();

        $analysis = NewsAnalysis::query()->sole();
        $this->assertSame('daily_summary', $analysis->type);
        $this->assertSame(AnalysisStatus::Pending, $analysis->status);

        Queue::assertPushed(RunNewsDailySummary::class, 1);
    }

    public function test_news_job_marks_failed_when_the_setting_is_deleted_before_it_runs(): void
    {
        $user = User::factory()->create();
        $setting = $this->makeSetting($user);
        $item = $this->makeNewsItem();

        $analysis = $user->newsAnalyses()->create([
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'pending',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'related_symbols' => [],
            'raw_output' => [],
            'data_as_of' => now(),
        ]);

        $settingId = $setting->id;
        $setting->delete();

        Http::preventStrayRequests();
        (new RunNewsItemAnalysis($analysis->id, $settingId, 'llama3.1'))->handle(
            app(NewsAnalysisService::class),
            app(LlmProviderFactory::class),
        );

        $this->assertSame(AnalysisStatus::Failed, $analysis->fresh()->status);
    }

    public function test_pending_status_is_exposed_to_the_stock_page(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL']);

        $this->actingAs($user)->post("/stocks/{$instrument->id}/analyses");

        $props = $this->actingAs($user)
            ->get('/stocks/search?symbol=AAPL')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('pending', $props['analyses'][0]['status']);
    }
}
