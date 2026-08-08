<?php

namespace Tests\Feature\Watchlist;

use App\Enums\AnalysisStatus;
use App\Jobs\RunWatchlistAnalysis;
use App\Models\Instrument;
use App\Models\LlmProviderSetting;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistAnalysis;
use App\Services\Analysis\WatchlistAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WatchlistAnalysisTest extends TestCase
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

    /** 建一張含指定標的的自選清單。 */
    private function makeWatchlist(User $user, Instrument ...$instruments): Watchlist
    {
        $watchlist = $user->watchlists()->create(['name' => '我的清單']);

        foreach ($instruments as $index => $instrument) {
            $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => $index]);
        }

        return $watchlist;
    }

    private const LLM_ENDPOINT = 'http://localhost:11434/v1/chat/completions';

    public function test_store_returns_pending_row_and_dispatches_without_calling_the_llm(): void
    {
        Queue::fake();
        // request 內不該有任何外送 HTTP（LLM／行情都該在 job 裡）。
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $setting = $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'market' => 'US']);
        $this->makeWatchlist($user, $instrument);

        $this->actingAs($user)
            ->post('/watchlists/analysis')
            ->assertRedirect('/watchlists/analysis');

        $analysis = WatchlistAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Pending, $analysis->status);
        $this->assertSame('pending', $analysis->provider_type);
        $this->assertSame($user->id, $analysis->user_id);

        Queue::assertPushed(RunWatchlistAnalysis::class, 1);
        $this->assertSame($setting->id, $user->defaultLlmSetting()?->id);
    }

    public function test_store_is_blocked_when_the_watchlist_is_empty(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeSetting($user);
        // 有清單但沒有任何標的。
        $user->watchlists()->create(['name' => '空清單']);

        $this->actingAs($user)
            ->post('/watchlists/analysis')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, WatchlistAnalysis::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_store_is_blocked_without_an_llm_setting(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'market' => 'US']);
        $this->makeWatchlist($user, $instrument);

        $this->actingAs($user)
            ->post('/watchlists/analysis')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, WatchlistAnalysis::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_show_page_renders_with_watchlist_summary(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'market' => 'US']);
        $this->makeWatchlist($user, $instrument);

        $props = $this->actingAs($user)
            ->get('/watchlists/analysis')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(1, $props['watchlistSummary']['count']);
        $this->assertContains('NVDA', $props['watchlistSummary']['symbols']);
    }

    public function test_queued_job_fills_the_report_from_the_llm(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'market' => 'US']);
        $this->makeWatchlist($user, $instrument);

        Http::fake([
            self::LLM_ENDPOINT => Http::response([
                'model' => 'llama3.1',
                'choices' => [['message' => [
                    'content' => '{"summary":"# 明日劇本\n中性偏多","points":["費半轉強"],"symbols":["NVDA"]}',
                ]]],
            ], 200),
        ]);

        // sync driver：dispatch 當下就跑完，等同 worker 取出執行。
        $this->actingAs($user)->post('/watchlists/analysis');

        $analysis = WatchlistAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Completed, $analysis->status);
        $this->assertSame('ollama', $analysis->provider_type);
        $this->assertStringContainsString('明日劇本', (string) $analysis->summary);
        $this->assertSame(['NVDA'], $analysis->related_symbols);
        // 資料層必須存在，且逐檔技術指標算得出來。
        $this->assertNotEmpty($analysis->payload['background']);
        $this->assertNotEmpty($analysis->payload['stocks']);
        $this->assertTrue($analysis->payload['stocks'][0]['available']);
    }

    public function test_symbols_outside_the_watchlist_are_dropped(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'market' => 'US']);
        $this->makeWatchlist($user, $instrument);

        Http::fake([
            self::LLM_ENDPOINT => Http::response([
                'model' => 'llama3.1',
                // 模型多吐了一個不在自選清單裡的 AAPL，須被過濾。
                'choices' => [['message' => ['content' => '{"summary":"x","points":[],"symbols":["NVDA","AAPL"]}']]],
            ], 200),
        ]);

        $this->actingAs($user)->post('/watchlists/analysis');

        $this->assertSame(['NVDA'], WatchlistAnalysis::query()->sole()->related_symbols);
    }

    public function test_job_marks_failed_but_keeps_the_data_layer_when_the_llm_errors(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'market' => 'US']);
        $this->makeWatchlist($user, $instrument);

        Http::fake([
            self::LLM_ENDPOINT => Http::response([], 500),
        ]);

        $this->actingAs($user)->post('/watchlists/analysis');

        $analysis = WatchlistAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Failed, $analysis->status);
        $this->assertSame('error', $analysis->provider_type);
        // AI 失敗不得丟掉資料層。
        $this->assertNotEmpty($analysis->payload['stocks']);
        $this->assertNotEmpty($analysis->payload['background']);
    }

    public function test_service_degrades_to_a_rule_summary_without_a_setting(): void
    {
        Http::preventStrayRequests();

        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'market' => 'US']);

        $result = app(WatchlistAnalysisService::class)->analyze([$instrument], null, 'reference-model');

        $this->assertSame('none', $result['provider']);
        $this->assertStringContainsString('未經 AI 分析', $result['summary']);
        $this->assertNotEmpty($result['payload']['stocks']);
        $this->assertSame(['NVDA'], $result['symbols']);
    }

    public function test_taiwan_symbols_carry_chip_and_margin_summaries(): void
    {
        Http::preventStrayRequests();

        // fake driver 下，籌碼/融資走 FakeChip/FakeMargin，不打網路。
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $result = app(WatchlistAnalysisService::class)->analyze([$instrument], null, 'reference-model');

        $stock = $result['payload']['stocks'][0];
        $this->assertSame('2330.TW', $stock['symbol']);
        $this->assertNotNull($stock['chip']);
        $this->assertNotNull($stock['margin']);
    }
}
