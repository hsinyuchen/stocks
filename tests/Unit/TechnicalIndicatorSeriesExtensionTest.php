<?php

namespace Tests\Unit;

use App\Services\TechnicalIndicatorService;
use PHPUnit\Framework\TestCase;

class TechnicalIndicatorSeriesExtensionTest extends TestCase
{
    /** @return list<array<string, float|int|string>> */
    private function prices(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $close = 100 + $i;
            $out[] = [
                'date' => sprintf('2026-01-%02d', ($i % 28) + 1),
                'open' => $close - 0.5, 'high' => $close + 1, 'low' => $close - 1,
                'close' => $close, 'volume' => 1000 + $i,
            ];
        }

        return $out;
    }

    public function test_series_includes_new_indicator_keys_aligned_with_close(): void
    {
        $series = (new TechnicalIndicatorService)->series($this->prices(70));
        $n = count($series['close']);

        foreach (['ma60', 'rsi', 'obv', 'boll_upper', 'boll_middle', 'boll_lower'] as $key) {
            $this->assertArrayHasKey($key, $series);
            $this->assertCount($n, $series[$key]);
        }
    }

    public function test_warmup_nulls(): void
    {
        $series = (new TechnicalIndicatorService)->series($this->prices(70));

        $this->assertNull($series['ma60'][58]);
        $this->assertNotNull($series['ma60'][59]);
        $this->assertNull($series['rsi'][13]);
        $this->assertNotNull($series['rsi'][14]);
        $this->assertNull($series['boll_upper'][18]);
        $this->assertNotNull($series['boll_upper'][19]);
    }

    public function test_rsi_of_monotonic_up_series_is_100(): void
    {
        // 連續上漲：無下跌日，RSI = 100
        $series = (new TechnicalIndicatorService)->series($this->prices(30));

        $this->assertSame(100.0, $series['rsi'][20]);
    }

    public function test_obv_accumulates_signed_volume(): void
    {
        $prices = [
            ['date' => '2026-01-01', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10, 'volume' => 100],
            ['date' => '2026-01-02', 'open' => 10, 'high' => 12, 'low' => 10, 'close' => 11, 'volume' => 200], // 漲 +200
            ['date' => '2026-01-03', 'open' => 11, 'high' => 11, 'low' => 9, 'close' => 10, 'volume' => 300],  // 跌 -300
            ['date' => '2026-01-04', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10, 'volume' => 400],  // 平 0
        ];

        $series = (new TechnicalIndicatorService)->series($prices);

        $this->assertSame([0, 200, -100, -100], $series['obv']);
    }

    public function test_bollinger_middle_equals_ma20(): void
    {
        $series = (new TechnicalIndicatorService)->series($this->prices(30));

        $this->assertSame($series['ma20'][25], $series['boll_middle'][25]);
        $this->assertGreaterThan($series['boll_middle'][25], $series['boll_upper'][25]);
        $this->assertLessThan($series['boll_middle'][25], $series['boll_lower'][25]);
    }
}
