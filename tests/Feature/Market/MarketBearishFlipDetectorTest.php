<?php

namespace Tests\Feature\Market;

use App\Contracts\FuturesDataProvider;
use App\Contracts\MarketDataProvider;
use App\Contracts\MarketInstitutionalProvider;
use App\Data\DailyPriceData;
use App\Data\FuturesMarketData;
use App\Data\MarketInstitutionalData;
use App\Data\MarketQuoteData;
use App\Services\Market\MarketBearishFlipDetector;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketBearishFlipDetectorTest extends TestCase
{
    /**
     * 綁定四個維度的資料源。各參數控制該維度是否成立。
     */
    private function bindDimensions(
        bool $futuresShort = true,
        bool $spotSelling = true,
        bool $indexDeclining = true,
        bool $fxRising = true,
    ): void {
        Cache::flush();

        // 期貨：連續淨空（非結算週）或收斂到未達門檻。
        $futuresSeries = $futuresShort
            ? [['date' => '2026-08-04', 'net' => -26000], ['date' => '2026-08-05', 'net' => -28000], ['date' => '2026-08-06', 'net' => -31000]]
            : [['date' => '2026-08-04', 'net' => -1000], ['date' => '2026-08-05', 'net' => -1200], ['date' => '2026-08-06', 'net' => -900]];

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

        // 現貨：外資連續大賣（≤ -150億）或小賣。
        $spot = $spotSelling ? -20_000_000_000 : -1_000_000_000;
        $spotSeries = [
            ['date' => '2026-08-04', 'net' => $spot],
            ['date' => '2026-08-05', 'net' => $spot],
            ['date' => '2026-08-06', 'net' => $spot],
        ];

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

        // 行情：^TWII 下跌（破均線）或上漲；USDTWD 走升（台幣貶）或走貶。
        $index = $this->indexPrices('^TWII', $indexDeclining);
        $fx = $this->fxPrices('USDTWD=X', $fxRising);

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

    public function test_triggers_when_all_four_dimensions_align(): void
    {
        $this->bindDimensions();

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertTrue($result['triggered']);
        $this->assertSame(
            ['futures' => true, 'spot' => true, 'technical' => true, 'fx' => true],
            $result['dimensions'],
        );
    }

    public function test_does_not_trigger_when_spot_not_selling(): void
    {
        $this->bindDimensions(spotSelling: false);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertFalse($result['triggered']);
        $this->assertFalse($result['dimensions']['spot']);
        $this->assertStringContainsString('現貨連續大賣', $result['reason']);
    }

    public function test_does_not_trigger_when_index_above_moving_averages(): void
    {
        $this->bindDimensions(indexDeclining: false);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertFalse($result['triggered']);
        $this->assertFalse($result['dimensions']['technical']);
    }

    public function test_does_not_trigger_when_twd_not_depreciating(): void
    {
        $this->bindDimensions(fxRising: false);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertFalse($result['triggered']);
        $this->assertFalse($result['dimensions']['fx']);
    }

    public function test_does_not_trigger_when_futures_not_short(): void
    {
        $this->bindDimensions(futuresShort: false);

        $result = app(MarketBearishFlipDetector::class)->detect();

        $this->assertFalse($result['triggered']);
        $this->assertFalse($result['dimensions']['futures']);
    }
}
