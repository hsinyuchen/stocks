<?php

namespace Tests\Feature\BrokerBranch;

use App\Models\Instrument;
use App\Services\Analysis\MarketWeightAnalysisService;
use App\Services\Analysis\SymbolContextService;
use App\Services\Analysis\WatchlistAnalysisService;
use App\Services\BrokerBranch\BrokerBranchDataService;
use App\Services\BrokerBranch\FinMindBrokerBranchDataProvider;
use App\Support\BrokerBranchGate;
use App\Support\FinMindGate;
use App\Support\FinMindTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrokerBranchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function payload(): array
    {
        // 兩日、兩券商：9200 連續買超、1360 連續賣超。
        return [
            'msg' => 'success',
            'data' => [
                ['date' => '2026-08-03', 'securities_trader_id' => '9200', 'securities_trader' => '凱基-台北', 'buy_volume' => 900_000, 'sell_volume' => 100_000],
                ['date' => '2026-08-03', 'securities_trader_id' => '1360', 'securities_trader' => '港商麥格理', 'buy_volume' => 100_000, 'sell_volume' => 800_000],
                ['date' => '2026-08-04', 'securities_trader_id' => '9200', 'securities_trader' => '凱基-台北', 'buy_volume' => 900_000, 'sell_volume' => 100_000],
                ['date' => '2026-08-04', 'securities_trader_id' => '1360', 'securities_trader' => '港商麥格理', 'buy_volume' => 100_000, 'sell_volume' => 800_000],
            ],
        ];
    }

    public function test_provider_parses_rows_and_sends_per_user_token(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        $resolver = new FinMindTokenResolver;
        $resolver->useToken('SPONSOR-TOKEN');

        $rows = (new FinMindBrokerBranchDataProvider($resolver))->fetch('2330.TW', 30);

        $this->assertNotEmpty($rows);
        // 升冪、net = buy - sell。
        $this->assertSame('2026-08-03', $rows[0]->date);
        $this->assertSame(800_000, $rows[0]->netShares);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'dataset=TaiwanStockTradingDailyReportSecIdAgg')
            && str_contains($request->url(), 'data_id=2330')
            && str_contains($request->url(), 'token=SPONSOR-TOKEN'));
    }

    public function test_sponsor_limit_degrades_independently_without_tripping_finmind_gate(): void
    {
        // 免費 token 打 Sponsor dataset 被擋：付費牆訊息。
        Http::fake(['*api.finmindtrade.com*' => Http::response(['msg' => 'Your level is free. Please upgrade.'], 402)]);

        $rows = (new FinMindBrokerBranchDataProvider(app(FinMindTokenResolver::class)))->fetch('2330.TW', 30);

        $this->assertSame([], $rows);
        // 券商分點自己的守門開啟……
        $this->assertTrue(BrokerBranchGate::isUnavailable());
        // ……但不得連坐全站 FinMindGate（免費功能如行情/三大法人不受影響）。
        $this->assertFalse(FinMindGate::isTripped(), 'Sponsor 受限不得冷卻全站 FinMindGate。');
    }

    public function test_summary_computes_top_movers_streak_and_concentration(): void
    {
        // fake driver：BrokerBranchDataProvider 綁 FakeBrokerBranchDataProvider。
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $summary = app(BrokerBranchDataService::class)->summaryFor($instrument);

        $this->assertNotNull($summary);
        $this->assertTrue($summary['available']);
        // 凱基連續買超最大 → 主力買超第一；麥格理連續賣超最大 → 主力賣超第一。
        $this->assertSame('凱基-台北', $summary['top_buyers'][0]['broker']);
        $this->assertSame('港商麥格理', $summary['top_sellers'][0]['broker']);
        // 連續同向天數等於 fake 的 bar 數（min(history_days,15)=15）。
        $this->assertSame(15, $summary['top_buyers'][0]['streak_days']);
        // 集中度為 0~1 之間。
        $this->assertGreaterThan(0, $summary['concentration']['buy_topn_ratio']);
        $this->assertLessThanOrEqual(1, $summary['concentration']['buy_topn_ratio']);
    }

    public function test_summary_is_null_for_non_taiwan(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'market' => 'US']);

        $this->assertNull(app(BrokerBranchDataService::class)->summaryFor($instrument));
    }

    public function test_summary_is_null_when_gate_marks_unavailable(): void
    {
        BrokerBranchGate::markUnavailable();

        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $this->assertNull(app(BrokerBranchDataService::class)->summaryFor($instrument));
    }

    public function test_symbol_context_attaches_broker_branch_to_rule_signal(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
        $summary = app(BrokerBranchDataService::class)->summaryFor($instrument);

        $context = app(SymbolContextService::class)->forSymbol('2330.TW', [], [], $summary);

        $this->assertArrayHasKey('broker_branch', $context['rule_signal']);
        $this->assertTrue($context['rule_signal']['broker_branch']['available']);
    }

    public function test_watchlist_analysis_includes_broker_branch_per_stock(): void
    {
        Http::preventStrayRequests();

        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $result = app(WatchlistAnalysisService::class)->analyze([$instrument], null, 'reference-model');

        $this->assertNotNull($result['payload']['stocks'][0]['broker_branch']);
        $this->assertTrue($result['payload']['stocks'][0]['broker_branch']['available']);
    }

    public function test_market_weight_analysis_includes_broker_branch_per_stock(): void
    {
        Http::preventStrayRequests();

        $result = app(MarketWeightAnalysisService::class)->analyze(null, 'reference-model');

        $this->assertNotNull($result['payload']['stocks'][0]['broker_branch']);
        $this->assertTrue($result['payload']['stocks'][0]['broker_branch']['available']);
    }
}
