<?php

namespace Tests\Feature\Screener;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Services\Screener\BacktestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacktestServiceTest extends TestCase
{
    use RefreshDatabase;

    private function bindProvider(callable $closeFor, int $bars = 200): void
    {
        $this->app->bind(MarketDataProvider::class, fn () => new class($closeFor, $bars) implements MarketDataProvider
        {
            public function __construct(private $closeFor, private int $bars) {}

            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, now()->toIso8601String());
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                $start = CarbonImmutable::parse('2025-01-01');
                $out = [];

                for ($i = 0; $i < $this->bars; $i++) {
                    $close = ($this->closeFor)($i);
                    $out[] = new DailyPriceData(
                        symbol: $symbol,
                        date: $start->addDays($i)->toDateString(),
                        open: $close, high: $close, low: $close, close: $close, volume: 1000,
                    );
                }

                return $out;
            }
        });
    }

    private function service(): BacktestService
    {
        return app(BacktestService::class);
    }

    public function test_produces_signals_and_forward_returns(): void
    {
        // 單調上升：站上 MA20 幾乎每天成立。
        $this->bindProvider(fn (int $i): float => 100.0 + $i);

        $result = $this->service()->run(['AAA' => 'Alpha'], ['above_ma20'], [], 200, [1, 5]);

        $this->assertSame(1, $result['scanned']);
        $this->assertGreaterThan(0, $result['signals']);
        $this->assertGreaterThan(0, $result['stats'][1]['samples']);
        $this->assertSame(100.0, $result['stats'][1]['win_rate'], '單調上升時每個訊號都應獲利。');
    }

    /**
     * 基準必須存在。規則賺 2% 而同期基準賺 3% 是輸，沒有基準就無法分辨
     * 「規則有效」與「這段期間本來就在漲」。
     */
    public function test_reports_a_baseline_for_comparison(): void
    {
        $this->bindProvider(fn (int $i): float => 100.0 + $i);

        $stats = $this->service()->run(['AAA' => 'A'], ['above_ma20'], [], 200, [5])['stats'][5];

        $this->assertNotNull($stats['baseline_mean']);
        $this->assertNotNull($stats['baseline_win_rate']);
        $this->assertSame(round($stats['mean'] - $stats['baseline_mean'], 4), $stats['edge']);
    }

    /**
     * 最後 maxHorizon 根不可納入樣本——它們的前瞻報酬還沒走完，
     * 納入會讓最近的結果系統性偏向未實現的方向。
     */
    public function test_excludes_bars_without_a_complete_forward_window(): void
    {
        $this->bindProvider(fn (int $i): float => 100.0 + $i, bars: 60);

        $result = $this->service()->run(['AAA' => 'A'], ['above_ma20'], [], 60, [20]);

        foreach ($result['stats'][20]['samples'] > 0 ? [true] : [] as $_) {
            // 每個樣本都必須有非 null 的報酬，代表視窗完整。
            $this->assertNotNull($result['stats'][20]['mean']);
        }

        // 60 根、暖身 30、前瞻 20 → 最多 11 個可評估點。
        $this->assertLessThanOrEqual(11, $result['stats'][20]['samples']);
    }

    /** 歷史長度不足以涵蓋暖身加前瞻視窗的標的必須跳過，不可產生半截樣本。 */
    public function test_symbols_with_insufficient_history_are_skipped(): void
    {
        $this->bindProvider(fn (int $i): float => 100.0 + $i, bars: 20);

        $result = $this->service()->run(['AAA' => 'A'], ['above_ma20'], [], 20, [20]);

        $this->assertSame(0, $result['scanned']);
        $this->assertCount(1, $result['failures']);
    }

    /**
     * 籌碼、基本面、訂單庫存、社交套利、產業動能規則不支援回放，必須明確回報。
     *
     * 它們的 matchesAt() 永遠回 false（用當下資料評估過去是前視偏誤），
     * 混進必要條件會讓命中數直接歸零——不回報的話使用者會誤以為「這組規則
     * 歷史上從沒訊號」。
     *
     * 訂單庫存規則額外驗證 unsupportedRules() 的判準本身：backtestContext()
     * 只替 MarginRule 收集需求，OrderInventoryRule 不是 MarginRule，理論上
     * 永遠不會被收集到 $needs——但那道過濾器一旦被放寬（例如日後有人想讓訂單
     * 庫存規則也支援時點截斷），unsupportedRules() 的判準（requires() 非空
     * 且非 MarginRule）仍會正確把它列為不支援，不會因為過濾器變動而漏判。
     *
     * 社交套利與產業動能兩條規則同理，且各自另有不能回放的理由：news_items 只保留
     * 90 天，而產業中位數的歷史從未被保存過（每檔只留最新一列）。
     */
    public function test_rules_requiring_extra_data_are_reported_as_unsupported(): void
    {
        $this->bindProvider(fn (int $i): float => 100.0 + $i);

        $result = $this->service()->run(
            ['AAA' => 'A'],
            ['above_ma20', 'foreign_buying_streak', 'order_inventory_b_plus', 'early_social_arbitrage', 'industry_outperformer'],
            [],
            200,
            [5],
        );

        // 用「完整清單」而不是逐條 assertContains：後者少列一條規則仍會通過，
        // 而少列一條的後果正是這條測試要防的——使用者拿到一份看似有效、實則
        // 該規則從未命中的回測結果。整份比對才釘得住「每一條都要在清單上」。
        $this->assertEqualsCanonicalizing(
            ['foreign_buying_streak', 'order_inventory_b_plus', 'early_social_arbitrage', 'industry_outperformer'],
            $result['unsupported_rules'],
            '需要額外資料的規則全部都要被列為不支援回測，一條都不能漏。',
        );
        $this->assertSame(0, $result['signals'], '含不支援的規則時不得產生訊號。');
    }

    public function test_exclude_rules_reduce_the_signal_count(): void
    {
        $this->bindProvider(fn (int $i): float => 100.0 + $i);

        $plain = $this->service()->run(['AAA' => 'A'], ['above_ma20'], [], 200, [5]);
        $filtered = $this->service()->run(['AAA' => 'A'], ['above_ma20'], ['rsi_overbought'], 200, [5]);

        $this->assertLessThan($plain['signals'], $filtered['signals'], '單調上升時 RSI 長期超買，排除後應大幅減少。');
    }

    /** 下跌行情的勝率必須是 0，確認報酬計算方向正確。 */
    public function test_declining_series_yields_zero_win_rate(): void
    {
        $this->bindProvider(fn (int $i): float => 1000.0 - $i);

        $stats = $this->service()->run(['AAA' => 'A'], ['below_ma20'], [], 200, [5])['stats'][5];

        $this->assertSame(0.0, $stats['win_rate']);
        $this->assertLessThan(0, $stats['mean']);
    }
}
