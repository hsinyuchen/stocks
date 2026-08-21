<?php

namespace Tests\Unit;

use App\Contracts\YieldCurveProvider;
use App\Data\RatesRegimeData;
use App\Data\YieldCurveData;
use App\Services\Rates\RatesRegimeService;
use App\Services\Rates\YieldCurveService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RatesRegimeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config()->set('rates.spread', ['long' => '10y', 'short' => '3m']);
        config()->set('rates.windows', [
            '20d' => ['days' => 20, 'level_bp' => 10.0, 'shape_bp' => 10.0],
            '60d' => ['days' => 60, 'level_bp' => 16.0, 'shape_bp' => 13.0],
        ]);
        config()->set('rates.primary_window', '20d');
        config()->set('rates.inversion_lookback_days', 60);
    }

    /**
     * 以每根固定變動量產生曲線。
     *
     * @param  float  $longStep  10Y 每根變動（百分點，正為上行）
     * @param  float  $shortStep  3M 每根變動
     * @param  float  $longStart  10Y 起始值
     * @param  float  $shortStart  3M 起始值
     */
    private function regime(
        float $longStep,
        float $shortStep,
        float $longStart = 4.00,
        float $shortStart = 3.00,
        int $bars = 130,
    ): RatesRegimeData {
        $long = [];
        $short = [];

        for ($i = 0; $i < $bars; $i++) {
            $date = sprintf('2026-%02d-%02d', 1 + intdiv($i, 28), ($i % 28) + 1);
            $long[$date] = $longStart + $i * $longStep;
            $short[$date] = $shortStart + $i * $shortStep;
        }

        $curve = YieldCurveData::aligned(['10y' => $long, '3m' => $short]);

        $provider = new class($curve) implements YieldCurveProvider
        {
            public function __construct(private readonly YieldCurveData $curve) {}

            public function curve(array $tenors, int $days): YieldCurveData
            {
                return $this->curve;
            }
        };

        return (new RatesRegimeService(new YieldCurveService($provider)))->current();
    }

    public function test_bear_steepening_when_yields_rise_and_spread_widens(): void
    {
        // 10Y +1bp/根、3M 不動 → 20 根 Δ10Y=+20bp(>10)、Δ利差=+20bp(>10)。
        $regime = $this->regime(longStep: 0.01, shortStep: 0.0);

        $this->assertTrue($regime->available);
        $this->assertSame('bear', $regime->window('20d')['level']);
        $this->assertSame('steepening', $regime->window('20d')['shape']);
        $this->assertSame('bear_steepening', $regime->window('20d')['quadrant']);
    }

    public function test_bear_flattening_when_short_end_rises_faster(): void
    {
        // 10Y +1bp/根、3M +2bp/根 → 殖利率上行但利差收窄。
        $regime = $this->regime(longStep: 0.01, shortStep: 0.02);

        $this->assertSame('bear', $regime->window('20d')['level']);
        $this->assertSame('flattening', $regime->window('20d')['shape']);
        $this->assertSame('bear_flattening', $regime->window('20d')['quadrant']);
    }

    public function test_bull_steepening_when_short_end_falls_faster(): void
    {
        $regime = $this->regime(longStep: -0.01, shortStep: -0.02);

        $this->assertSame('bull', $regime->window('20d')['level']);
        $this->assertSame('steepening', $regime->window('20d')['shape']);
        $this->assertSame('bull_steepening', $regime->window('20d')['quadrant']);
    }

    public function test_bull_flattening_when_long_end_falls_faster(): void
    {
        $regime = $this->regime(longStep: -0.02, shortStep: -0.01);

        $this->assertSame('bull', $regime->window('20d')['level']);
        $this->assertSame('flattening', $regime->window('20d')['shape']);
        $this->assertSame('bull_flattening', $regime->window('20d')['quadrant']);
    }

    public function test_quadrant_is_null_when_level_is_neutral(): void
    {
        // 10Y 幾乎不動（20 根 Δ=+2bp < 10bp 門檻），3M 下行使利差擴大。
        $regime = $this->regime(longStep: 0.001, shortStep: -0.01);
        $window = $regime->window('20d');

        $this->assertSame('neutral', $window['level']);
        $this->assertSame('steepening', $window['shape']);
        // 不強迫湊出象限：任一維中性即 null，避免用弱訊號產生強結論。
        $this->assertNull($window['quadrant']);
    }

    public function test_quadrant_is_null_when_shape_is_neutral(): void
    {
        // 兩端同步上行 → 殖利率明確上行，但利差幾乎不動。
        $regime = $this->regime(longStep: 0.01, shortStep: 0.0102);
        $window = $regime->window('20d');

        $this->assertSame('bear', $window['level']);
        $this->assertSame('neutral', $window['shape']);
        $this->assertNull($window['quadrant']);
    }

    public function test_delta_exactly_at_threshold_is_treated_as_neutral(): void
    {
        // 20 根 Δ10Y 剛好 +10bp，等於門檻。判定採嚴格大於，故為中性（保守側）。
        $regime = $this->regime(longStep: 0.005, shortStep: 0.005);
        $window = $regime->window('20d');

        $this->assertEqualsWithDelta(10.0, $window['delta_level_bp'], 0.001);
        $this->assertSame('neutral', $window['level']);
    }

    public function test_two_windows_may_disagree_and_both_are_reported(): void
    {
        config()->set('rates.windows', [
            '20d' => ['days' => 20, 'level_bp' => 10.0, 'shape_bp' => 10.0],
            '60d' => ['days' => 60, 'level_bp' => 16.0, 'shape_bp' => 13.0],
        ]);

        // 每根 +0.5bp：20 根 Δ=+10bp（等於門檻→中性），60 根 Δ=+30bp（>16→bear）。
        $regime = $this->regime(longStep: 0.005, shortStep: 0.0);

        $this->assertSame('neutral', $regime->window('20d')['level']);
        $this->assertSame('bear', $regime->window('60d')['level']);
    }

    public function test_inverted_flag_when_spread_is_negative(): void
    {
        // 3M 起點高於 10Y 且維持 → 利差為負。
        $regime = $this->regime(longStep: 0.0, shortStep: 0.0, longStart: 3.50, shortStart: 4.50);

        $this->assertTrue($regime->inverted);
        $this->assertFalse($regime->recentlyUninverted);
        $this->assertEqualsWithDelta(-100.0, $regime->spreadBp, 0.01);
    }

    public function test_recently_uninverted_when_spread_turned_positive_within_lookback(): void
    {
        // 起點倒掛（10Y 3.00 < 3M 4.00），10Y 每根 +0.8bp 逐步轉正。
        // 130 根、回看窗 60 根 → 只看第 70~129 根。轉正點在第 125 根
        // （spread[i] = -1.00 + 0.008i，i=125 時歸零），落在窗內第 55 根，
        // 故第 70~124 根仍是負值：curve 目前未倒掛，但回看窗內曾經倒掛。
        // 步幅必須夠小才能讓轉正點落在窗內；用 0.02 時轉正點在第 50 根，
        // 早於窗口起點（第 70 根），回看窗內已無倒掛可言。
        $regime = $this->regime(longStep: 0.008, shortStep: 0.0, longStart: 3.00, shortStart: 4.00);

        $this->assertFalse($regime->inverted);
        $this->assertTrue($regime->recentlyUninverted);
    }

    public function test_not_recently_uninverted_when_inversion_is_older_than_lookback(): void
    {
        config()->set('rates.inversion_lookback_days', 10);

        $regime = $this->regime(longStep: 0.02, shortStep: 0.0, longStart: 3.00, shortStart: 4.00);

        $this->assertFalse($regime->inverted);
        // 倒掛發生在很早期，已落在 10 根回看窗之外。
        $this->assertFalse($regime->recentlyUninverted);
    }

    public function test_unavailable_when_curve_is_empty(): void
    {
        $provider = new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                return YieldCurveData::empty();
            }
        };

        $regime = (new RatesRegimeService(new YieldCurveService($provider)))->current();

        $this->assertFalse($regime->available);
        $this->assertNull($regime->spreadBp);
        $this->assertNull($regime->primary());
        $this->assertFalse($regime->inverted);
    }

    public function test_unavailable_when_a_required_tenor_is_missing(): void
    {
        $curve = YieldCurveData::aligned(['10y' => ['2026-08-03' => 4.5, '2026-08-04' => 4.6]]);

        $provider = new class($curve) implements YieldCurveProvider
        {
            public function __construct(private readonly YieldCurveData $curve) {}

            public function curve(array $tenors, int $days): YieldCurveData
            {
                return $this->curve;
            }
        };

        $regime = (new RatesRegimeService(new YieldCurveService($provider)))->current();

        // 缺短端就算不出利差，整組判定不可用——不可拿單邊硬判方向。
        $this->assertFalse($regime->available);
    }

    public function test_window_delta_is_null_when_history_shorter_than_window(): void
    {
        // 只有 30 根，60d 窗口算不出來，但 20d 仍可用。
        $regime = $this->regime(longStep: 0.01, shortStep: 0.0, bars: 30);

        $this->assertTrue($regime->available);
        $this->assertSame('bear', $regime->window('20d')['level']);
        $this->assertNull($regime->window('60d')['delta_level_bp']);
        $this->assertSame('neutral', $regime->window('60d')['level']);
        $this->assertNull($regime->window('60d')['quadrant']);
    }

    public function test_primary_window_follows_config(): void
    {
        config()->set('rates.primary_window', '60d');

        $regime = $this->regime(longStep: 0.01, shortStep: 0.0);

        $this->assertSame(60, $regime->primary()['days']);
    }
}
