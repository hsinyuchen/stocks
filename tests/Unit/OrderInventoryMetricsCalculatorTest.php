<?php

namespace Tests\Unit;

use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Services\Fundamentals\OrderInventoryMetricsCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryMetricsCalculatorTest extends TestCase
{
    private function quarter(string $period, array $overrides = []): QuarterlyFinancials
    {
        return new QuarterlyFinancials(...array_merge([
            'period' => $period,
            'revenue' => 1000.0,
            'costOfGoodsSold' => 700.0,
            'grossProfit' => 300.0,
            'netIncome' => 200.0,
            'inventories' => 350.0,
            'accountsReceivable' => 500.0,
            'accountsPayable' => 280.0,
            'operatingCashFlow' => 180.0,
            'capex' => 100.0,
        ], $overrides));
    }

    #[Test]
    public function it_computes_turnover_days_on_period_end_balances(): void
    {
        $data = new OrderInventoryData(quarters: [$this->quarter('2026Q1')], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        // DIO = 350 / 700 × 91 = 45.5；用期末餘額，不是期初期末平均。
        $this->assertEqualsWithDelta(45.5, $m->dio, 0.001);
        // DSO = 500 / 1000 × 91 = 45.5
        $this->assertEqualsWithDelta(45.5, $m->dso, 0.001);
        // DPO = 280 / 700 × 91 = 36.4
        $this->assertEqualsWithDelta(36.4, $m->dpo, 0.001);
        // CCC = 45.5 + 45.5 − 36.4 = 54.6
        $this->assertEqualsWithDelta(54.6, $m->ccc, 0.001);
    }

    #[Test]
    public function it_returns_null_rather_than_zero_when_a_line_item_is_missing(): void
    {
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q1', ['inventories' => null])],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertNull($m->dio, 'inventories 缺值時 DIO 必須是 null，0 是合法天數');
        $this->assertNull($m->ccc, 'CCC 的任一組成缺值時整體為 null');
        $this->assertNotNull($m->dso, '其他指標不受影響');
    }

    #[Test]
    public function it_refuses_quarter_over_quarter_across_a_gap(): void
    {
        // 缺 2026Q1：序列裡 2026Q2 的前一個元素是 2025Q4，差兩季。
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2025Q4', ['inventories' => 100.0]),
            $this->quarter('2026Q2', ['inventories' => 200.0]),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertNull(
            $m->inventoriesQoq,
            '基期不是日曆上相鄰的前一季時必須回 null，不可拿 2025Q4 充當',
        );
        $this->assertNull($m->qoqBasePeriod);
    }

    #[Test]
    public function it_computes_quarter_over_quarter_when_the_base_is_adjacent(): void
    {
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2026Q1', ['inventories' => 100.0]),
            $this->quarter('2026Q2', ['inventories' => 120.0]),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertEqualsWithDelta(0.20, $m->inventoriesQoq, 0.0001);
        $this->assertSame('2026Q1', $m->qoqBasePeriod);
    }

    #[Test]
    public function it_wraps_the_year_when_finding_the_adjacent_quarter(): void
    {
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2025Q4', ['inventories' => 100.0]),
            $this->quarter('2026Q1', ['inventories' => 130.0]),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertEqualsWithDelta(0.30, $m->inventoriesQoq, 0.0001, 'Q1 的前一季是去年 Q4');
        $this->assertSame('2025Q4', $m->qoqBasePeriod);
    }

    #[Test]
    public function it_computes_year_over_year_only_against_the_same_quarter_last_year(): void
    {
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2025Q2', ['inventories' => 100.0]),
            $this->quarter('2025Q3', ['inventories' => 110.0]),
            $this->quarter('2025Q4', ['inventories' => 120.0]),
            $this->quarter('2026Q1', ['inventories' => 130.0]),
            $this->quarter('2026Q2', ['inventories' => 140.0]),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertEqualsWithDelta(0.40, $m->inventoriesYoy, 0.0001, '2026Q2 對 2025Q2');
        $this->assertSame('2025Q2', $m->yoyBasePeriod);
    }

    #[Test]
    public function it_averages_capex_ratio_over_the_trailing_quarters_excluding_the_latest(): void
    {
        $quarters = [];
        // 八季 capex/revenue = 0.10，最新一季 0.20。
        foreach (['2024Q3', '2024Q4', '2025Q1', '2025Q2', '2025Q3', '2025Q4', '2026Q1', '2026Q2'] as $p) {
            $quarters[] = $this->quarter($p, ['capex' => 100.0, 'revenue' => 1000.0]);
        }
        $quarters[] = $this->quarter('2026Q3', ['capex' => 200.0, 'revenue' => 1000.0]);

        $m = (new OrderInventoryMetricsCalculator)->calculate(
            new OrderInventoryData(quarters: $quarters, market: 'tw'),
        );

        $this->assertEqualsWithDelta(0.20, $m->capexToRevenue, 0.0001);
        $this->assertEqualsWithDelta(0.10, $m->capexToRevenueTrailingAverage, 0.0001);
        $this->assertSame(8, $m->trailingSamples);
    }

    #[Test]
    public function it_reports_no_trailing_average_when_samples_are_too_few(): void
    {
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2026Q1'),
            $this->quarter('2026Q2'),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertNull(
            $m->capexToRevenueTrailingAverage,
            '樣本不足 4 季時不給平均，否則兩季就敢談「近 8 季平均」',
        );
    }

    #[Test]
    public function taiwan_uses_monthly_revenue_for_the_growth_streak(): void
    {
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q2')],
            monthlyRevenue: [
                ['month' => '2026-04-01', 'revenue' => 100.0, 'yoy' => 0.05],
                ['month' => '2026-05-01', 'revenue' => 110.0, 'yoy' => 0.08],
                ['month' => '2026-06-01', 'revenue' => 120.0, 'yoy' => 0.11],
            ],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertSame(3, $m->revenueGrowthStreak);
        $this->assertSame('monthly', $m->revenueGrowthBasis);
        $this->assertSame('2026-06-01', $m->latestRevenueMonth);
    }

    #[Test]
    public function taiwan_streak_stops_at_the_most_recent_negative_month(): void
    {
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q2')],
            monthlyRevenue: [
                ['month' => '2026-03-01', 'revenue' => 90.0, 'yoy' => 0.10],
                ['month' => '2026-04-01', 'revenue' => 100.0, 'yoy' => -0.02],
                ['month' => '2026-05-01', 'revenue' => 110.0, 'yoy' => 0.08],
                ['month' => '2026-06-01', 'revenue' => 120.0, 'yoy' => 0.11],
            ],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertSame(2, $m->revenueGrowthStreak, '連續是從最新往回數，遇到負值就停');
    }

    #[Test]
    public function us_falls_back_to_quarterly_revenue_for_the_growth_streak(): void
    {
        // 美股無月營收（SEC 不提供），改數季營收 YoY 連正季數。
        $quarters = [
            $this->quarter('2025Q1', ['revenue' => 900.0]),
            $this->quarter('2025Q2', ['revenue' => 900.0]),
            $this->quarter('2025Q3', ['revenue' => 900.0]),
            $this->quarter('2025Q4', ['revenue' => 900.0]),
            $this->quarter('2026Q1', ['revenue' => 990.0]),
            $this->quarter('2026Q2', ['revenue' => 1000.0]),
        ];

        $m = (new OrderInventoryMetricsCalculator)->calculate(
            new OrderInventoryData(quarters: $quarters, monthlyRevenue: [], market: 'us'),
        );

        $this->assertSame(2, $m->revenueGrowthStreak);
        $this->assertSame('quarterly', $m->revenueGrowthBasis);
    }

    #[Test]
    public function it_reports_the_basis_as_none_when_neither_series_supports_a_streak(): void
    {
        $m = (new OrderInventoryMetricsCalculator)->calculate(
            new OrderInventoryData(quarters: [$this->quarter('2026Q2')], market: 'us'),
        );

        $this->assertNull($m->revenueGrowthStreak, '不可評估回 null，不是 0');
        $this->assertSame('none', $m->revenueGrowthBasis);
    }

    #[Test]
    public function gross_margin_falls_back_to_revenue_minus_cogs(): void
    {
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q1', ['grossProfit' => null])],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertEqualsWithDelta(0.30, $m->grossMargin, 0.0001);
    }
}
