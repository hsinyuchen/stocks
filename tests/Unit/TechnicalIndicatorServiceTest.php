<?php

namespace Tests\Unit;

use App\Data\DailyPriceData;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\TechnicalIndicatorService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

class TechnicalIndicatorServiceTest extends TestCase
{
    public function test_calculates_latest_indicator_snapshot(): void
    {
        $prices = (new FakeMarketDataProvider())->dailyPrices('AAPL', 60);
        $snapshot = (new TechnicalIndicatorService())->calculate($prices);

        $this->assertArrayHasKey('k', $snapshot);
        $this->assertArrayHasKey('d', $snapshot);
        $this->assertArrayHasKey('macd', $snapshot);
        $this->assertArrayHasKey('macd_signal', $snapshot);
        $this->assertArrayHasKey('macd_histogram', $snapshot);
        $this->assertArrayHasKey('ma5', $snapshot);
        $this->assertArrayHasKey('ma20', $snapshot);
        $this->assertGreaterThan(0, $snapshot['ma5']);
    }

    public function test_throws_for_empty_prices(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one price is required to calculate indicators.');

        (new TechnicalIndicatorService())->calculate([]);
    }

    public function test_calculates_indicators_for_one_day_price_history(): void
    {
        $snapshot = (new TechnicalIndicatorService())->calculate([
            $this->price(close: 123.45, high: 126.0, low: 121.0, open: 122.0),
        ]);

        $this->assertSame(123.45, $snapshot['ma5']);
        $this->assertSame(123.45, $snapshot['ma20']);
        $this->assertArrayHasKey('k', $snapshot);
        $this->assertArrayHasKey('d', $snapshot);
        $this->assertArrayHasKey('macd', $snapshot);
        $this->assertArrayHasKey('macd_signal', $snapshot);
        $this->assertArrayHasKey('macd_histogram', $snapshot);
    }

    public function test_flat_prices_produce_neutral_kd_values(): void
    {
        $snapshot = (new TechnicalIndicatorService())->calculate([
            $this->price(close: 100.0, high: 100.0, low: 100.0, open: 100.0),
            $this->price(close: 100.0, high: 100.0, low: 100.0, open: 100.0, date: '2026-06-19'),
            $this->price(close: 100.0, high: 100.0, low: 100.0, open: 100.0, date: '2026-06-20'),
        ]);

        $this->assertSame(50.0, $snapshot['k']);
        $this->assertSame(50.0, $snapshot['d']);
    }

    public function test_throws_for_invalid_price_item(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 must contain numeric open, high, low, close, and volume values.');

        (new TechnicalIndicatorService())->calculate([
            new stdClass(),
        ]);
    }

    public function test_throws_when_high_is_below_low(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 has invalid range: high must be greater than or equal to low.');

        (new TechnicalIndicatorService())->calculate([
            $this->price(close: 100.0, high: 95.0, low: 96.0),
        ]);
    }

    public function test_throws_when_close_is_outside_high_low_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 has invalid close: close must be within the low/high range.');

        (new TechnicalIndicatorService())->calculate([
            $this->price(close: 105.0, high: 102.0, low: 98.0),
        ]);
    }

    public function test_throws_when_open_is_outside_high_low_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 has invalid open: open must be within the low/high range.');

        (new TechnicalIndicatorService())->calculate([
            $this->price(close: 100.0, high: 102.0, low: 98.0, open: 103.0),
        ]);
    }

    public function test_throws_when_volume_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 has invalid volume: volume must be zero or greater.');

        (new TechnicalIndicatorService())->calculate([
            $this->price(close: 100.0, volume: -1),
        ]);
    }

    public function test_calculates_indicators_for_array_price_items(): void
    {
        $snapshot = (new TechnicalIndicatorService())->calculate([
            [
                'open' => 9.0,
                'high' => 11.0,
                'low' => 8.0,
                'close' => 10.0,
                'volume' => 1000,
            ],
            [
                'open' => 19.0,
                'high' => 21.0,
                'low' => 18.0,
                'close' => 20.0,
                'volume' => 1100,
            ],
        ]);

        $this->assertSame(15.0, $snapshot['ma5']);
        $this->assertSame(15.0, $snapshot['ma20']);
    }

    public function test_calculates_exact_moving_averages_for_short_deterministic_history(): void
    {
        $snapshot = (new TechnicalIndicatorService())->calculate([
            $this->price(close: 10.0, high: 10.0, low: 10.0, open: 10.0),
            $this->price(close: 20.0, high: 20.0, low: 20.0, open: 20.0, date: '2026-06-19'),
            $this->price(close: 30.0, high: 30.0, low: 30.0, open: 30.0, date: '2026-06-20'),
        ]);

        $this->assertSame(20.0, $snapshot['ma5']);
        $this->assertSame(20.0, $snapshot['ma20']);
    }

    public function test_macd_signal_is_ema_of_macd_series_not_a_fixed_ratio(): void
    {
        $prices = [];
        for ($i = 0; $i < 35; $i++) {
            $close = 100.0 + $i;
            $prices[] = $this->price(
                close: $close,
                high: $close + 0.5,
                low: $close - 0.5,
                open: $close - 0.2,
                date: sprintf('2026-05-%02d', ($i % 28) + 1),
            );
        }

        $snapshot = (new TechnicalIndicatorService())->calculate($prices);

        // On a steadily rising series the MACD line is positive and its EMA-9
        // signal line lags below it, so the histogram is positive...
        $this->assertGreaterThan(0, $snapshot['macd']);
        $this->assertGreaterThan(0, $snapshot['macd_signal']);
        $this->assertGreaterThan($snapshot['macd_signal'], $snapshot['macd']);
        $this->assertGreaterThan(0, $snapshot['macd_histogram']);

        // ...and the signal must NOT equal the old `macd * 0.8` approximation.
        $this->assertNotSame(round($snapshot['macd'] * 0.8, 4), $snapshot['macd_signal']);

        // Histogram is exactly macd - signal.
        $this->assertSame(
            round($snapshot['macd'] - $snapshot['macd_signal'], 4),
            $snapshot['macd_histogram'],
        );
    }

    public function test_series_returns_aligned_arrays_for_charting(): void
    {
        $prices = (new FakeMarketDataProvider())->dailyPrices('AAPL', 60);
        $service = new TechnicalIndicatorService();
        $series = $service->series($prices);

        $keys = ['dates', 'close', 'volume', 'ma5', 'ma20', 'k', 'd', 'macd', 'signal', 'histogram'];
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $series);
            $this->assertCount(60, $series[$key], "$key should have one entry per input price");
        }

        foreach ($series['dates'] as $date) {
            $this->assertIsString($date);
        }
    }

    public function test_series_last_values_match_calculate(): void
    {
        $prices = (new FakeMarketDataProvider())->dailyPrices('AAPL', 60);
        $service = new TechnicalIndicatorService();

        $snapshot = $service->calculate($prices);
        $series = $service->series($prices);

        $this->assertSame($snapshot['k'], end($series['k']));
        $this->assertSame($snapshot['d'], end($series['d']));
        $this->assertSame($snapshot['macd'], end($series['macd']));
        $this->assertSame($snapshot['macd_signal'], end($series['signal']));
        $this->assertSame($snapshot['ma5'], end($series['ma5']));
        $this->assertSame($snapshot['ma20'], end($series['ma20']));
    }

    public function test_series_moving_averages_are_null_until_enough_points(): void
    {
        $prices = (new FakeMarketDataProvider())->dailyPrices('AAPL', 60);
        $series = (new TechnicalIndicatorService())->series($prices);

        // MA5 needs 5 points: indices 0..3 are null, index 4 onward is a float.
        $this->assertNull($series['ma5'][0]);
        $this->assertNull($series['ma5'][3]);
        $this->assertIsFloat($series['ma5'][4]);

        // MA20 needs 20 points: index 18 null, index 19 onward is a float.
        $this->assertNull($series['ma20'][18]);
        $this->assertIsFloat($series['ma20'][19]);
    }

    public function test_series_throws_for_empty_prices(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one price is required to calculate indicators.');

        (new TechnicalIndicatorService())->series([]);
    }

    public function test_series_throws_for_invalid_price_item(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 must contain numeric open, high, low, close, and volume values.');

        (new TechnicalIndicatorService())->series([
            new stdClass(),
        ]);
    }

    private function price(
        float $close,
        float $high = 102.0,
        float $low = 98.0,
        float $open = 99.0,
        int $volume = 1000,
        string $date = '2026-06-18',
        string $symbol = 'AAPL',
    ): DailyPriceData {
        return new DailyPriceData(
            symbol: $symbol,
            date: $date,
            open: $open,
            high: $high,
            low: $low,
            close: $close,
            volume: $volume,
        );
    }
}
