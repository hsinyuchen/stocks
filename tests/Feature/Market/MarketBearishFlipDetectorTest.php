<?php

namespace Tests\Feature\Market;

use App\Contracts\FuturesDataProvider;
use App\Contracts\MarketDataProvider;
use App\Contracts\MarketInstitutionalProvider;
use App\Contracts\YieldCurveProvider;
use App\Data\DailyPriceData;
use App\Data\FuturesMarketData;
use App\Data\MarketInstitutionalData;
use App\Data\MarketQuoteData;
use App\Data\YieldCurveData;
use App\Services\Market\MarketBearishFlipDetector;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketBearishFlipDetectorTest extends TestCase
{
    /**
     * 綁定五個維度的資料源。
     *
     * 每個參數可為 true（成立）、false（不成立）、null（資料源缺料，應計入
     * unavailable 而非單純不成立）。
     */
    private function bindDimensions(
        ?bool $futuresShort = true,
        ?bool $spotSelling = true,
        ?bool $indexDeclining = true,
        ?bool $fxRising = true,
        ?bool $ratesBearish = true,
    ): void {
        Cache::flush();

        // 期貨：連續淨空、未達門檻、或序列不足（缺料）。
        $futuresSeries = match ($futuresShort) {
            null => [],
            true => [['date' => '2026-08-04', 'net' => -26000], ['date' => '2026-08-05', 'net' => -28000], ['date' => '2026-08-06', 'net' => -31000]],
            false => [['date' => '2026-08-04', 'net' => -1000], ['date' => '2026-08-05', 'net' => -1200], ['date' => '2026-08-06', 'net' => -900]],
        };

        $this->app->bind(FuturesDataProvider::class, fn () => new class($futuresSeries) implements FuturesDataProvider
        {
            public function __construct(private readonly array $series) {}

            public function snapshot(): FuturesMarketData
            {
                return FuturesMarketData::empty();
            }

            public function foreignNetOiSeries(int $days): array
            {
                return array_slice($this->series, -$days);
            }
        });

        // 現貨：外資連續大賣（≤ -150億）、小賣、或無序列（缺料）。
        $spotSeries = $spotSelling === null ? [] : (function () use ($spotSelling): array {
            $net = $spotSelling ? -20_000_000_000 : -1_000_000_000;

            return [
                ['date' => '2026-08-04', 'net' => $net],
                ['date' => '2026-08-05', 'net' => $net],
                ['date' => '2026-08-06', 'net' => $net],
            ];
        })();

        $this->app->bind(MarketInstitutionalProvider::class, fn () => new class($spotSeries) implements MarketInstitutionalProvider
        {
            public function __construct(private readonly array $series) {}

            public function latest(): MarketInstitutionalData
            {
                return MarketInstitutionalData::empty();
            }

            public function foreignNetSeries(int $days): array
            {
                return array_slice($this->series, -$days);
            }
        });

        // 行情：^TWII 破均線與否；USDTWD 走升與否。null 代表回空序列（缺料）。
        $index = $indexDeclining === null ? [] : $this->indexPrices('^TWII', $indexDeclining);
        $fx = $fxRising === null ? [] : $this->fxPrices('USDTWD=X', $fxRising);

        $this->app->bind(MarketDataProvider::class, fn () => new class($index, $fx) implements MarketDataProvider
        {
            /**
             * @param  list<DailyPriceData>  $index
             * @param  list<DailyPriceData>  $fx
             */
            public function __construct(private readonly array $index, private readonly array $fx) {}

            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 1.0, 0.0, 0.0, '2026-08-06T00:00:00+00:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                return str_contains($symbol, 'TWD') ? $this->fx : $this->index;
            }
        });

        // 利率：殖利率上行（bear，維度成立）、下行（bull，不成立）、或無曲線（缺料）。
        $curve = $ratesBearish === null
            ? YieldCurveData::empty()
            : $this->yieldCurve($ratesBearish);

        $this->app->bind(YieldCurveProvider::class, fn () => new class($curve) implements YieldCurveProvider
        {
            public function __construct(private readonly YieldCurveData $curve) {}

            public function curve(array $tenors, int $days): YieldCurveData
            {
                return $this->curve;
            }
        });
    }

    /** 殖利率上行（bear）或下行（bull），兩者皆維持正利差（未倒掛）。 */
    private function yieldCurve(bool $bearish): YieldCurveData
    {
        $step = $bearish ? 0.01 : -0.01;
        $long = [];
        $short = [];

        for ($i = 0; $i < 130; $i++) {
            $date = sprintf('2026-%02d-%02d', 1 + intdiv($i, 28), ($i % 28) + 1);
            $long[$date] = 4.50 + $i * $step;
            $short[$date] = 3.00;
        }

        return YieldCurveData::aligned(['10y' => $long, '3m' => $short]);
    }

    /** @return list<DailyPriceData> */
    private function indexPrices(string $symbol, bool $declining): array
    {
        $prices = [];
        for ($i = 0; $i < 65; $i++) {
            $close = $declining ? 20000 - $i * 50 : 16000 + $i * 50;
            $prices[] = new DailyPriceData($symbol, sprintf('2026-05-%02d', ($i % 28) + 1), $close, $close + 30, $close - 30, $close, 1000);
        }

        return $prices;
    }

    /** @return list<DailyPriceData> */
    private function fxPrices(string $symbol, bool $rising): array
    {
        $prices = [];
        for ($i = 0; $i < 30; $i++) {
            $close = $rising ? 30.0 + $i * 0.02 : 33.0 - $i * 0.02;
            $prices[] = new DailyPriceData($symbol, sprintf('2026-07-%02d', ($i % 28) + 1), $close, $close + 0.05, $close - 0.05, $close, 0);
        }

        return $prices;
    }

    public function test_triggers_when_all_five_dimensions_align(): void
    {
        $this->bindDimensions();

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertTrue($result['triggered']);
        $this->assertSame(5, $result['score']);
        $this->assertSame(5, $result['max']);
        $this->assertSame([], $result['unavailable']);
        $this->assertSame(
            ['futures' => true, 'spot' => true, 'technical' => true, 'fx' => true, 'rates' => true],
            $result['dimensions'],
        );
    }

    public function test_triggers_at_four_of_five(): void
    {
        $this->bindDimensions(fxRising: false);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertTrue($result['triggered']);
        $this->assertSame(4, $result['score']);
        $this->assertFalse($result['dimensions']['fx']);
    }

    public function test_does_not_trigger_at_three_of_five(): void
    {
        $this->bindDimensions(fxRising: false, ratesBearish: false);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertFalse($result['triggered']);
        $this->assertSame(3, $result['score']);
        $this->assertStringContainsString('5 維中 3 維成立', $result['reason']);
    }

    public function test_rates_dimension_holds_when_yields_rise(): void
    {
        $this->bindDimensions(ratesBearish: true);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertTrue($result['dimensions']['rates']);
    }

    public function test_rates_dimension_fails_when_yields_fall_without_inversion(): void
    {
        $this->bindDimensions(ratesBearish: false);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertFalse($result['dimensions']['rates']);
        $this->assertNotContains('rates', $result['unavailable']);
    }

    public function test_unavailable_data_is_distinguished_from_a_failed_condition(): void
    {
        // 舊版 4 維 AND 把「抓不到」與「不成立」都壓成 false，使用者無從分辨，
        // 且單一資料源斷線會讓警報整天不可能觸發。
        $this->bindDimensions(fxRising: null, ratesBearish: null);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertSame(['fx', 'rates'], $result['unavailable']);
        $this->assertFalse($result['dimensions']['fx']);
        $this->assertFalse($result['dimensions']['rates']);
        $this->assertSame(3, $result['score']);
        $this->assertStringContainsString('無資料', $result['reason']);
    }

    public function test_still_triggers_with_one_data_source_down(): void
    {
        // 資料斷線不再讓警報靜默失效：其餘四維成立仍可觸發。
        $this->bindDimensions(ratesBearish: null);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertTrue($result['triggered']);
        $this->assertSame(4, $result['score']);
        $this->assertSame(['rates'], $result['unavailable']);
    }

    public function test_min_dimensions_is_configurable(): void
    {
        config()->set('alerts.market_bearish_flip.min_dimensions', 5);
        $this->bindDimensions(fxRising: false);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertFalse($result['triggered']);
        $this->assertSame(5, $result['min_score']);
    }

    public function test_alert_evaluator_contract_is_preserved(): void
    {
        // AlertEvaluator 只讀 detect()['triggered']，改計分制不得破壞這個鍵。
        $this->bindDimensions();

        $this->assertArrayHasKey('triggered', app(MarketBearishFlipDetector::class)->detect());
    }
}
