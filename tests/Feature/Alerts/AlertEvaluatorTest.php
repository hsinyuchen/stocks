<?php

namespace Tests\Feature\Alerts;

use App\Contracts\FuturesDataProvider;
use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\FuturesMarketData;
use App\Data\MarketQuoteData;
use App\Models\Alert;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Market\MarketBearishFlipDetector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AlertEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * per-symbol 報價 + changePercent + 可拋例外 + 呼叫計數 stub。
     * 不用 FakeMarketDataProvider（對任何 symbol 恆回 128.50、不拋例外，
     * 測不出 per-symbol 命中與失敗分支）。
     *
     * @param  array<string, array{price: float, changePercent: float}>  $quotes
     * @param  list<string>  $failing
     * @param  array<string, list<DailyPriceData>>  $daily
     */
    private function bindProvider(array $quotes, array $failing = [], array $daily = []): object
    {
        $stub = new class($quotes, $failing, $daily) implements MarketDataProvider
        {
            public int $quoteCalls = 0;

            /**
             * @param  array<string, array{price: float, changePercent: float}>  $quotes
             * @param  list<string>  $failing
             * @param  array<string, list<DailyPriceData>>  $daily
             */
            public function __construct(
                private readonly array $quotes,
                private readonly array $failing,
                private readonly array $daily,
            ) {}

            public function quote(string $symbol): MarketQuoteData
            {
                $this->quoteCalls++;

                if (in_array($symbol, $this->failing, true)) {
                    throw new \RuntimeException('quote down');
                }

                $q = $this->quotes[$symbol];

                return new MarketQuoteData($symbol, $q['price'], 0.0, $q['changePercent'], '2026-07-08T01:00:00+00:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                return $this->daily[$symbol] ?? [];
            }
        };

        $this->app->instance(MarketDataProvider::class, $stub);

        return $stub;
    }

    private function alert(User $user, string $symbol, string $type, ?float $threshold = null, ?string $signalKey = null): Alert
    {
        // instruments.symbol 為 unique；同 symbol 多警報須共用同一 instrument。
        $instrument = Instrument::query()->firstWhere('symbol', $symbol)
            ?? Instrument::factory()->create(['symbol' => $symbol, 'name' => $symbol]);
        $alert = new Alert(['instrument_id' => $instrument->id, 'type' => $type, 'threshold' => $threshold, 'signal_key' => $signalKey]);
        $user->alerts()->save($alert);

        return $alert;
    }

    public function test_price_above_triggers_when_price_exceeds_threshold(): void
    {
        $this->bindProvider(['NVDA' => ['price' => 150.0, 'changePercent' => 1.0]]);
        $user = User::factory()->create();
        $alert = $this->alert($user, 'NVDA', 'price_above', threshold: 100.0);

        $count = app(AlertEvaluator::class)->evaluate($user);

        $this->assertSame(1, $count);
        $alert->refresh();
        $this->assertSame('triggered', $alert->status);
        $this->assertSame('150.0000', $alert->triggered_price);
        $this->assertNotNull($alert->triggered_at);
    }

    public function test_price_above_does_not_trigger_below_threshold(): void
    {
        $this->bindProvider(['NVDA' => ['price' => 90.0, 'changePercent' => 1.0]]);
        $user = User::factory()->create();
        $alert = $this->alert($user, 'NVDA', 'price_above', threshold: 100.0);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluate($user));
        $this->assertSame('active', $alert->refresh()->status);
    }

    public function test_price_below_and_change_pct_conditions(): void
    {
        $this->bindProvider([
            'AAA' => ['price' => 50.0, 'changePercent' => -3.0],
            'BBB' => ['price' => 50.0, 'changePercent' => 6.0],
        ]);
        $user = User::factory()->create();
        $below = $this->alert($user, 'AAA', 'price_below', threshold: 60.0);      // 50 < 60 命中
        $pctDown = $this->alert($user, 'AAA', 'change_pct_below', threshold: -2.0); // -3 < -2 命中
        $pctUp = $this->alert($user, 'BBB', 'change_pct_above', threshold: 5.0);   // 6 > 5 命中

        $this->assertSame(3, app(AlertEvaluator::class)->evaluate($user));
        $this->assertSame('triggered', $below->refresh()->status);
        $this->assertSame('triggered', $pctDown->refresh()->status);
        $this->assertSame('triggered', $pctUp->refresh()->status);
    }

    public function test_signal_alert_reuses_screener_rule(): void
    {
        // above_ma20：最後一根收盤 > MA20 即命中。造 30 根單調上升即可。
        $daily = [];
        for ($i = 0; $i < 30; $i++) {
            $close = 100 + $i;
            $daily[] = new DailyPriceData('SIG.TW', sprintf('2026-01-%02d', $i + 1), $close, $close + 1, $close - 1, $close, 1000);
        }
        $this->bindProvider(['SIG.TW' => ['price' => 130.0, 'changePercent' => 1.0]], daily: ['SIG.TW' => $daily]);
        $user = User::factory()->create();
        $alert = $this->alert($user, 'SIG.TW', 'signal', signalKey: 'above_ma20');

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate($user));
        $this->assertSame('triggered', $alert->refresh()->status);
    }

    public function test_signal_alert_triggers_even_when_quote_fails(): void
    {
        $daily = [];
        for ($i = 0; $i < 30; $i++) {
            $close = 100 + $i;
            $daily[] = new DailyPriceData('SIG.TW', sprintf('2026-01-%02d', $i + 1), $close, $close + 1, $close - 1, $close, 1000);
        }
        // quote() throws for SIG.TW, but dailyPrices succeeds
        $this->bindProvider([], failing: ['SIG.TW'], daily: ['SIG.TW' => $daily]);
        $user = User::factory()->create();
        $alert = $this->alert($user, 'SIG.TW', 'signal', signalKey: 'above_ma20');

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate($user));
        $alert->refresh();
        $this->assertSame('triggered', $alert->status);
        $this->assertNull($alert->triggered_price);   // 報價失敗 → 觸發價為 null
    }

    /** 30 根單調上升的日 K，供訊號類警報通過「最少 30 根」門檻。 */
    private function ascendingDaily(string $symbol): array
    {
        $daily = [];
        for ($i = 0; $i < 30; $i++) {
            $close = 100 + $i;
            $daily[] = new DailyPriceData($symbol, sprintf('2026-01-%02d', $i + 1), $close, $close + 1, $close - 1, $close, 1000);
        }

        return $daily;
    }

    /**
     * 籌碼類訊號警報要真的會觸發。
     *
     * 修正前 AlertEvaluator 不傳 context，ChipRule::matches() 讀不到籌碼一律回 false，
     * 使用者選得到 foreign_buying_streak 卻永遠不觸發。FakeChipDataProvider 外資連續
     * 買超遞增，streak >= 3 應命中。
     */
    public function test_chip_signal_alert_triggers_with_context(): void
    {
        $this->bindProvider(['CHIP.TW' => ['price' => 130.0, 'changePercent' => 1.0]], daily: ['CHIP.TW' => $this->ascendingDaily('CHIP.TW')]);
        $user = User::factory()->create();
        $alert = $this->alert($user, 'CHIP.TW', 'signal', signalKey: 'foreign_buying_streak');

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate($user));
        $this->assertSame('triggered', $alert->refresh()->status);
    }

    /** 融資類訊號警報要真的會觸發（FakeMargin 使用率 10%，門檻壓到 5 即命中）。 */
    public function test_margin_signal_alert_triggers_with_context(): void
    {
        config(['margin.signal.usage_high' => 5.0]);
        $this->bindProvider(['MRGN.TW' => ['price' => 130.0, 'changePercent' => 1.0]], daily: ['MRGN.TW' => $this->ascendingDaily('MRGN.TW')]);
        $user = User::factory()->create();
        $alert = $this->alert($user, 'MRGN.TW', 'signal', signalKey: 'high_margin_usage');

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate($user));
        $this->assertSame('triggered', $alert->refresh()->status);
    }

    /**
     * 訂單庫存訊號警報要真的會觸發，走 Assessor → context → Rule 整條鏈——不能只靠
     * OrderInventoryScreenRulesTest 手寫 context 的 DTO 形狀，那組測試從沒真正呼叫過
     * AlertEvaluator::contextFor()，接線斷了也不會發現（同一份 contextFor() 邏輯在
     * ScreenerService 與 AlertEvaluator 各複製一份，兩邊要分別驗證）。
     * FakeCompanyFinancialsProvider 的預設情境已由 OrderInventorySeamTest 驗證會評成 B+。
     */
    public function test_order_inventory_signal_alert_triggers_with_context(): void
    {
        // 序列季末日寫死 2026-06-30，時效判定比的是 now()，須凍結（理由同 OrderInventorySeamTest）。
        $this->travelTo(CarbonImmutable::parse('2026-08-24 09:00:00'));

        $this->bindProvider(['OI.TW' => ['price' => 130.0, 'changePercent' => 1.0]], daily: ['OI.TW' => $this->ascendingDaily('OI.TW')]);
        $user = User::factory()->create();
        $alert = $this->alert($user, 'OI.TW', 'signal', signalKey: 'order_inventory_b_plus');

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate($user));
        $this->assertSame('triggered', $alert->refresh()->status);
    }

    /** 美股沒有籌碼資料：籌碼類訊號警報不得誤觸發（context 為空 → 規則回 false）。 */
    public function test_chip_signal_does_not_trigger_for_us_symbol(): void
    {
        $this->bindProvider(['NVDA' => ['price' => 130.0, 'changePercent' => 1.0]], daily: ['NVDA' => $this->ascendingDaily('NVDA')]);
        $user = User::factory()->create();
        $alert = $this->alert($user, 'NVDA', 'signal', signalKey: 'foreign_buying_streak');

        $this->assertSame(0, app(AlertEvaluator::class)->evaluate($user));
        $this->assertSame('active', $alert->refresh()->status);
    }

    /** 綁定回傳「連續淨空」序列的期貨 provider，讓大盤翻空成立。 */
    private function bindFlippingFutures(): void
    {
        Cache::flush();

        $this->app->bind(FuturesDataProvider::class, fn () => new class implements FuturesDataProvider
        {
            public function snapshot(): FuturesMarketData
            {
                return FuturesMarketData::empty();
            }

            public function foreignNetOiSeries(int $days): array
            {
                return array_slice([
                    ['date' => '2026-08-04', 'net' => -26000],
                    ['date' => '2026-08-05', 'net' => -28000],
                    ['date' => '2026-08-06', 'net' => -31000],
                ], -$days);
            }
        });
    }

    /** 大盤層級警報：無 instrument，外資期貨連續翻空時觸發，觸發價為 null。 */
    public function test_market_futures_flip_alert_triggers(): void
    {
        $this->bindFlippingFutures();
        $user = User::factory()->create();
        $alert = new Alert(['type' => 'market_futures_flip']);
        $user->alerts()->save($alert);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate($user));
        $alert->refresh();
        $this->assertSame('triggered', $alert->status);
        $this->assertNull($alert->triggered_price);
    }

    /** 未翻空（FakeFuturesDataProvider 收在 -8,000，未達門檻）→ 不觸發。 */
    public function test_market_futures_flip_alert_does_not_trigger_when_not_flipped(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $alert = new Alert(['type' => 'market_futures_flip']);
        $user->alerts()->save($alert);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluate($user));
        $this->assertSame('active', $alert->refresh()->status);
    }

    /** 大盤真翻空：四維共振偵測器回報成立時觸發（維度組合邏輯由 detector 測試涵蓋）。 */
    public function test_market_bearish_flip_alert_triggers(): void
    {
        $this->app->bind(MarketBearishFlipDetector::class, fn () => new class extends MarketBearishFlipDetector
        {
            // 略過父建構子依賴：本 stub 只覆寫 detect()。
            public function __construct() {}

            public function detect(): array
            {
                // 完整比照 detect() 的真實回傳形狀：AlertEvaluator 觸發時會讀
                // score/max/unavailable/reason 記 log（見 finding #4），缺鍵會
                // 直接噴 Undefined array key。
                return [
                    'triggered' => true,
                    'score' => 4,
                    'max' => 5,
                    'min_score' => 4,
                    'dimensions' => ['futures' => true, 'spot' => true, 'technical' => true, 'fx' => true, 'rates' => false],
                    'unavailable' => [],
                    'reason' => null,
                ];
            }
        });

        $user = User::factory()->create();
        $alert = new Alert(['type' => 'market_bearish_flip']);
        $user->alerts()->save($alert);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate($user));
        $alert->refresh();
        $this->assertSame('triggered', $alert->status);
        $this->assertNull($alert->triggered_price);
    }

    public function test_one_shot_does_not_retrigger(): void
    {
        $this->bindProvider(['NVDA' => ['price' => 150.0, 'changePercent' => 1.0]]);
        $user = User::factory()->create();
        $this->alert($user, 'NVDA', 'price_above', threshold: 100.0);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluate($user));
        // 第二次：已 triggered，不再計數
        $this->assertSame(0, app(AlertEvaluator::class)->evaluate($user));
    }

    public function test_quote_failure_keeps_alert_active_and_memoizes_calls(): void
    {
        $stub = $this->bindProvider(
            ['GOOD' => ['price' => 200.0, 'changePercent' => 1.0]],
            failing: ['BAD'],
        );
        $user = User::factory()->create();
        // 同一失敗 symbol 三個警報：上游只該被呼叫一次（memoize）
        $badInstrument = Instrument::factory()->create(['symbol' => 'BAD', 'name' => 'BAD']);
        foreach (['price_above', 'price_below', 'change_pct_above'] as $type) {
            $a = new Alert(['instrument_id' => $badInstrument->id, 'type' => $type, 'threshold' => 1]);
            $user->alerts()->save($a);
        }
        $good = $this->alert($user, 'GOOD', 'price_above', threshold: 100.0);

        $count = app(AlertEvaluator::class)->evaluate($user);

        $this->assertSame(1, $count);                          // 只有 GOOD 觸發
        $this->assertSame('triggered', $good->refresh()->status);
        // BAD 的三個警報維持 active
        $this->assertSame(3, $user->alerts()->where('status', 'active')->count());
        // BAD 上游只被呼叫 1 次（memoize），加 GOOD 1 次 = 共 2 次
        $this->assertSame(2, $stub->quoteCalls);
    }
}
