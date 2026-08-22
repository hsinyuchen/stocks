<?php

namespace Tests\Unit;

use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use PHPUnit\Framework\TestCase;

class OrderInventoryDataTest extends TestCase
{
    private function quarter(string $period, ?float $inventories = null): QuarterlyFinancials
    {
        return new QuarterlyFinancials(
            period: $period,
            endDate: '2026-06-30',
            revenue: 1000.0,
            costOfGoodsSold: 700.0,
            grossProfit: 300.0,
            inventories: $inventories,
        );
    }

    public function test_empty_reports_unavailable(): void
    {
        $data = OrderInventoryData::empty();

        $this->assertFalse($data->hasAny());
        $this->assertNull($data->latestQuarter());
        $this->assertSame([], $data->quarters);
    }

    public function test_latest_quarter_is_the_last_element(): void
    {
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2025Q4'),
            $this->quarter('2026Q1'),
            $this->quarter('2026Q2'),
        ]);

        $this->assertTrue($data->hasAny());
        $this->assertSame('2026Q2', $data->latestQuarter()->period);
    }

    public function test_quarter_lookup_by_period(): void
    {
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2026Q1', 500.0),
            $this->quarter('2026Q2', 600.0),
        ]);

        $this->assertSame(500.0, $data->quarter('2026Q1')->inventories);
        $this->assertNull($data->quarter('2025Q4'));
    }

    public function test_missing_quarter_values_stay_null_not_zero(): void
    {
        // 0 是合法的財報數字，不可與「無資料」混用。
        $q = new QuarterlyFinancials(period: '2026Q2');

        $this->assertNull($q->inventories);
        $this->assertNull($q->operatingCashFlow);
        $this->assertNull($q->capex);
    }

    public function test_inventory_composition_flag_defaults_to_false(): void
    {
        // 台股拿不到存貨組成，預設為 false；美股實作才會設為 true。
        $this->assertFalse(OrderInventoryData::empty()->inventoryCompositionAvailable);
    }

    public function test_survives_array_round_trip_for_cache(): void
    {
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q1', 500.0), $this->quarter('2026Q2', 600.0)],
            monthlyRevenue: [['month' => '2026-06-01', 'revenue' => 120.0, 'yoy' => 0.15]],
            market: 'tw',
            industry: '光電業',
            inventoryCompositionAvailable: false,
            dataAsOf: '2026-06-30',
        );

        $restored = OrderInventoryData::fromArray($data->toArray());

        $this->assertSame('tw', $restored->market);
        $this->assertSame('光電業', $restored->industry);
        $this->assertCount(2, $restored->quarters);
        $this->assertSame(600.0, $restored->latestQuarter()->inventories);
        $this->assertSame(0.15, $restored->monthlyRevenue[0]['yoy']);
    }

    public function test_round_trip_preserves_nulls(): void
    {
        $data = new OrderInventoryData(quarters: [new QuarterlyFinancials(period: '2026Q2')]);

        $restored = OrderInventoryData::fromArray($data->toArray());

        $this->assertNull($restored->latestQuarter()->inventories);
        $this->assertNull($restored->latestQuarter()->revenue);
    }
}
