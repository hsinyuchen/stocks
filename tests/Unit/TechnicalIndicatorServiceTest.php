<?php

namespace Tests\Unit;

use App\Data\DailyPriceData;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\TechnicalIndicatorService;
use InvalidArgumentException;
use stdClass;
use Tests\TestCase;

class TechnicalIndicatorServiceTest extends TestCase
{
    public function test_calculates_latest_indicator_snapshot(): void
    {
        $prices = (new FakeMarketDataProvider)->dailyPrices('AAPL', 60);
        $snapshot = (new TechnicalIndicatorService)->calculate($prices);

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

        (new TechnicalIndicatorService)->calculate([]);
    }

    /**
     * 單根資料不足以算出任何週期型指標，必須回 null 而非拿該根值冒充。
     * 舊版會把 123.45 同時當成 MA5 與 MA20，SignalEngine 便會據此給出 stance。
     */
    public function test_one_day_price_history_yields_null_period_indicators(): void
    {
        $snapshot = (new TechnicalIndicatorService)->calculate([
            $this->price(close: 123.45, high: 126.0, low: 121.0, open: 122.0),
        ]);

        $this->assertNull($snapshot['ma5']);
        $this->assertNull($snapshot['ma20']);
        $this->assertNull($snapshot['macd']);
        $this->assertNull($snapshot['macd_signal']);
        $this->assertNull($snapshot['macd_histogram']);

        // KD 以 50 起算並遞迴平滑，單根即有定義值，刻意不回 null。
        $this->assertIsFloat($snapshot['k']);
        $this->assertIsFloat($snapshot['d']);
    }

    public function test_flat_prices_produce_neutral_kd_values(): void
    {
        $snapshot = (new TechnicalIndicatorService)->calculate([
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

        (new TechnicalIndicatorService)->calculate([
            new stdClass,
        ]);
    }

    public function test_throws_when_high_is_below_low(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 has invalid range: high must be greater than or equal to low.');

        (new TechnicalIndicatorService)->calculate([
            $this->price(close: 100.0, high: 95.0, low: 96.0),
        ]);
    }

    public function test_throws_when_close_is_outside_high_low_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 has invalid close: close must be within the low/high range.');

        (new TechnicalIndicatorService)->calculate([
            $this->price(close: 105.0, high: 102.0, low: 98.0),
        ]);
    }

    public function test_throws_when_open_is_outside_high_low_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 has invalid open: open must be within the low/high range.');

        (new TechnicalIndicatorService)->calculate([
            $this->price(close: 100.0, high: 102.0, low: 98.0, open: 103.0),
        ]);
    }

    public function test_throws_when_volume_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 has invalid volume: volume must be zero or greater.');

        (new TechnicalIndicatorService)->calculate([
            $this->price(close: 100.0, volume: -1),
        ]);
    }

    public function test_calculates_indicators_for_array_price_items(): void
    {
        $snapshot = (new TechnicalIndicatorService)->calculate([
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

        // 陣列形式的價格項可被接受並完成計算；兩根仍在 MA 暖身期，故為 null。
        $this->assertIsFloat($snapshot['k']);
        $this->assertNull($snapshot['ma5']);
        $this->assertNull($snapshot['ma20']);
    }

    /** 資料足夠時，MA5 / MA20 必須是尾端視窗的精確算術平均。 */
    public function test_calculates_exact_moving_averages_once_periods_are_covered(): void
    {
        $closes = [];
        $prices = [];

        for ($i = 0; $i < 20; $i++) {
            $close = 10.0 + $i;
            $closes[] = $close;
            $prices[] = $this->price(
                close: $close,
                high: $close,
                low: $close,
                open: $close,
                date: sprintf('2026-05-%02d', $i + 1),
            );
        }

        $snapshot = (new TechnicalIndicatorService)->calculate($prices);

        // 尾五根 25..29 平均 27；全 20 根 10..29 平均 19.5。
        $this->assertSame(27.0, $snapshot['ma5']);
        $this->assertSame(19.5, $snapshot['ma20']);
        $this->assertSame(array_sum(array_slice($closes, -5)) / 5, $snapshot['ma5']);
        $this->assertSame(array_sum($closes) / 20, $snapshot['ma20']);

        // 20 根仍不足 MACD 的 33 根暖身鏈。
        $this->assertNull($snapshot['macd_histogram']);
    }

    public function test_macd_signal_is_ema_of_macd_series_not_a_fixed_ratio(): void
    {
        // 加速上漲而非等速：等速直線上漲時 MACD 會收斂成常數，其 EMA9 signal
        // 與之完全相等、histogram 為 0——那是數學上的正確結果，無法用來驗證
        // signal 落後於 MACD。要驗證落後關係，MACD 本身必須仍在變動。
        $prices = [];
        for ($i = 0; $i < 40; $i++) {
            $close = 100.0 + ($i ** 2) / 10;
            $prices[] = $this->price(
                close: $close,
                high: $close + 0.5,
                low: $close - 0.5,
                open: $close - 0.2,
                date: sprintf('2026-05-%02d', ($i % 28) + 1),
            );
        }

        $snapshot = (new TechnicalIndicatorService)->calculate($prices);

        // MACD 持續走高時，其 EMA9 signal 落後於 MACD，histogram 為正。
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
        $prices = (new FakeMarketDataProvider)->dailyPrices('AAPL', 60);
        $service = new TechnicalIndicatorService;
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
        $prices = (new FakeMarketDataProvider)->dailyPrices('AAPL', 60);
        $service = new TechnicalIndicatorService;

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
        $prices = (new FakeMarketDataProvider)->dailyPrices('AAPL', 60);
        $series = (new TechnicalIndicatorService)->series($prices);

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

        (new TechnicalIndicatorService)->series([]);
    }

    public function test_series_throws_for_invalid_price_item(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price item at index 0 must contain numeric open, high, low, close, and volume values.');

        (new TechnicalIndicatorService)->series([
            new stdClass,
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
