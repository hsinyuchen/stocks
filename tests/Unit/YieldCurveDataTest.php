<?php

namespace Tests\Unit;

use App\Data\YieldCurveData;
use PHPUnit\Framework\TestCase;

class YieldCurveDataTest extends TestCase
{
    public function test_aligned_keeps_only_dates_present_in_every_tenor(): void
    {
        $curve = YieldCurveData::aligned([
            '10y' => ['2026-08-03' => 4.50, '2026-08-04' => 4.60, '2026-08-05' => 4.70],
            // 3M 缺 08-04：不取交集的話利差會拿不同日期相減。
            '3m' => ['2026-08-03' => 3.50, '2026-08-05' => 3.60],
        ]);

        $this->assertSame(['2026-08-03', '2026-08-05'], $curve->dates);
        $this->assertSame([4.50, 4.70], $curve->series['10y']);
        $this->assertSame([3.50, 3.60], $curve->series['3m']);
        $this->assertSame('2026-08-05', $curve->asOf());
    }

    public function test_aligned_returns_empty_when_no_common_dates(): void
    {
        $curve = YieldCurveData::aligned([
            '10y' => ['2026-08-03' => 4.50],
            '3m' => ['2026-08-04' => 3.50],
        ]);

        $this->assertFalse($curve->hasAny());
        $this->assertNull($curve->asOf());
    }

    public function test_spread_series_and_current_spread_in_basis_points(): void
    {
        $curve = YieldCurveData::aligned([
            '10y' => ['2026-08-03' => 4.50, '2026-08-04' => 4.70],
            '3m' => ['2026-08-03' => 3.50, '2026-08-04' => 3.60],
        ]);

        $this->assertEqualsWithDelta([1.00, 1.10], $curve->spreadSeries('10y', '3m'), 0.0001);
        $this->assertEqualsWithDelta(110.0, $curve->spreadBp('10y', '3m'), 0.01);
    }

    public function test_tenor_delta_is_expressed_in_basis_points(): void
    {
        $curve = YieldCurveData::aligned([
            '10y' => ['2026-08-03' => 4.50, '2026-08-04' => 4.60, '2026-08-05' => 4.70],
            '3m' => ['2026-08-03' => 3.50, '2026-08-04' => 3.50, '2026-08-05' => 3.50],
        ]);

        // 4.70 - 4.50 = 0.20 個百分點 = 20bp（window=2 表示回看兩根）。
        $this->assertEqualsWithDelta(20.0, $curve->tenorDeltaBp('10y', 2), 0.01);
        // 3M 不動，故利差變動等於 10Y 變動。
        $this->assertEqualsWithDelta(20.0, $curve->spreadDeltaBp('10y', '3m', 2), 0.01);
    }

    public function test_delta_is_null_when_history_shorter_than_window(): void
    {
        $curve = YieldCurveData::aligned([
            '10y' => ['2026-08-03' => 4.50, '2026-08-04' => 4.60],
            '3m' => ['2026-08-03' => 3.50, '2026-08-04' => 3.50],
        ]);

        $this->assertNull($curve->tenorDeltaBp('10y', 5));
        $this->assertNull($curve->spreadDeltaBp('10y', '3m', 5));
    }

    public function test_missing_tenor_yields_nulls_not_zeros(): void
    {
        $curve = YieldCurveData::aligned([
            '10y' => ['2026-08-03' => 4.50, '2026-08-04' => 4.60],
        ]);

        $this->assertNull($curve->latest('3m'));
        $this->assertNull($curve->spreadBp('10y', '3m'));
        $this->assertSame([], $curve->spreadSeries('10y', '3m'));
    }

    public function test_survives_array_round_trip_for_cache(): void
    {
        $curve = YieldCurveData::aligned([
            '10y' => ['2026-08-03' => 4.50, '2026-08-04' => 4.60],
            '3m' => ['2026-08-03' => 3.50, '2026-08-04' => 3.55],
        ]);

        $restored = YieldCurveData::fromArray($curve->toArray());

        $this->assertSame($curve->dates, $restored->dates);
        $this->assertSame($curve->series, $restored->series);
    }

    public function test_empty_curve_reports_unavailable(): void
    {
        $curve = YieldCurveData::empty();

        $this->assertFalse($curve->hasAny());
        $this->assertNull($curve->latest('10y'));
        $this->assertNull($curve->tenorDeltaBp('10y', 20));
    }
}
