<?php

namespace Tests\Unit;

use App\Services\TechnicalIndicatorService;
use PHPUnit\Framework\TestCase;

class KdRecursionTest extends TestCase
{
    /** @return list<array<string, float|int|string>> */
    private function prices(array $closes): array
    {
        return array_map(fn ($close, $i) => [
            'date' => sprintf('2026-01-%02d', $i + 1),
            'open' => $close, 'high' => $close + 1, 'low' => $close - 1,
            'close' => $close, 'volume' => 1000,
        ], $closes, array_keys($closes));
    }

    public function test_kd_is_recursive_not_fixed_50(): void
    {
        // 持續上漲：RSV 恆接近 100，真 KD 的 K 應遞迴逼近 100；
        // 舊簡化版每根都從 50 起算，K 被鎖在 ~66.7 以下。
        $closes = range(100, 130); // 31 根單調上升
        $series = (new TechnicalIndicatorService)->series($this->prices($closes));

        $n = count($closes) - 1;
        $this->assertGreaterThan(85, $series['k'][$n], '真 KD 遞迴下持續上漲的 K 應逼近 100');
        $this->assertGreaterThan(80, $series['d'][$n]);
    }

    public function test_kd_recursion_formula_exact(): void
    {
        // 手算驗證：K[i] = 2/3·K[i-1] + 1/3·RSV[i]，K[0]=D[0] 以 50 起算
        $closes = [10.0, 12.0];
        $series = (new TechnicalIndicatorService)->series($this->prices($closes));

        // i=0：high=11, low=9 → RSV = (10-9)/(11-9)*100 = 50 → K=2/3*50+1/3*50=50
        $this->assertEqualsWithDelta(50.0, $series['k'][0], 0.001);
        // i=1：期間 high=13, low=9 → RSV=(12-9)/(13-9)*100=75 → K=2/3*50+1/3*75=58.3333
        $this->assertEqualsWithDelta(58.3333, $series['k'][1], 0.001);
        // D[1]=2/3*50+1/3*58.3333=52.7778
        $this->assertEqualsWithDelta(52.7778, $series['d'][1], 0.001);
    }

    public function test_calculate_kd_matches_series_last_value(): void
    {
        $closes = range(100, 140);
        $service = new TechnicalIndicatorService;
        $prices = $this->prices($closes);

        $series = $service->series($prices);
        $snapshot = $service->calculate($prices);

        $n = count($closes) - 1;
        $this->assertSame($series['k'][$n], $snapshot['k']);
        $this->assertSame($series['d'][$n], $snapshot['d']);
    }
}
