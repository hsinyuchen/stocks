<?php

namespace Tests\Unit;

use App\Data\DailyPriceData;
use App\Services\PriceAggregationService;
use PHPUnit\Framework\TestCase;

class PriceAggregationServiceTest extends TestCase
{
    private function day(string $date, float $o, float $h, float $l, float $c, int $v): DailyPriceData
    {
        return new DailyPriceData('T', $date, $o, $h, $l, $c, $v);
    }

    public function test_weekly_aggregation_ohlcv(): void
    {
        // 2026-06-29(一) ~ 07-03(五) 同一 ISO 週；07-06(一) 下一週
        $daily = [
            $this->day('2026-06-29', 10, 12, 9, 11, 100),
            $this->day('2026-06-30', 11, 15, 10, 14, 200),
            $this->day('2026-07-03', 14, 16, 13, 15, 300),
            $this->day('2026-07-06', 15, 17, 14, 16, 400),
        ];

        $weeks = (new PriceAggregationService)->toWeekly($daily);

        $this->assertCount(2, $weeks);
        $this->assertSame('2026-06-29', $weeks[0]->date);
        $this->assertSame(10.0, $weeks[0]->open);
        $this->assertSame(16.0, $weeks[0]->high);
        $this->assertSame(9.0, $weeks[0]->low);
        $this->assertSame(15.0, $weeks[0]->close);
        $this->assertSame(600, $weeks[0]->volume);
        // 未完成的當週也輸出
        $this->assertSame('2026-07-06', $weeks[1]->date);
        $this->assertSame(400, $weeks[1]->volume);
    }

    public function test_weekly_groups_by_iso_week_across_year_boundary(): void
    {
        // 2025-12-31(三) 與 2026-01-02(五) 屬同一 ISO 週（2026-W01）
        $daily = [
            $this->day('2025-12-31', 10, 11, 9, 10, 100),
            $this->day('2026-01-02', 10, 12, 10, 12, 100),
        ];

        $weeks = (new PriceAggregationService)->toWeekly($daily);

        $this->assertCount(1, $weeks);
        $this->assertSame('2025-12-31', $weeks[0]->date);
        $this->assertSame(12.0, $weeks[0]->close);
    }

    public function test_monthly_aggregation(): void
    {
        $daily = [
            $this->day('2026-06-01', 10, 12, 9, 11, 100),
            $this->day('2026-06-30', 11, 13, 10, 12, 100),
            $this->day('2026-07-01', 12, 14, 11, 13, 100),
        ];

        $months = (new PriceAggregationService)->toMonthly($daily);

        $this->assertCount(2, $months);
        $this->assertSame('2026-06-01', $months[0]->date);
        $this->assertSame(10.0, $months[0]->open);
        $this->assertSame(12.0, $months[0]->close);
        $this->assertSame(200, $months[0]->volume);
    }

    public function test_empty_input_returns_empty(): void
    {
        $service = new PriceAggregationService;

        $this->assertSame([], $service->toWeekly([]));
        $this->assertSame([], $service->toMonthly([]));
    }
}
