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

    public function test_fiscal_fields_round_trip(): void
    {
        $quarter = new QuarterlyFinancials(period: '2025Q1', fiscalYear: 2026, fiscalPeriod: 'Q1');

        $restored = QuarterlyFinancials::fromArray($quarter->toArray());

        $this->assertSame(2026, $restored->fiscalYear);
        $this->assertSame('Q1', $restored->fiscalPeriod);
    }

    public function test_annual_revenue_defaults_to_empty_for_legacy_data_without_the_key(): void
    {
        // order_inventory 是 JSON 欄位，正式站有這個 task 之前存的舊資料，
        // 沒有 annual_revenue 這個鍵。fromArray() 不得因此拋錯。
        $legacy = OrderInventoryData::empty();
        $legacyArray = $legacy->toArray();
        unset($legacyArray['annual_revenue']);

        $restored = OrderInventoryData::fromArray($legacyArray);

        $this->assertSame([], $restored->annualRevenue);
    }

    public function test_annual_revenue_survives_a_json_database_round_trip_as_float(): void
    {
        // order_inventory 是 DB 的 JSON 欄位。SEC 申報金額幾乎都是整數美元，
        // json_encode(500.0) 會輸出 500，json_decode 讀回來就是 PHP int，
        // 與 annualRevenue 的 docblock 契約（revenue: float）不符。只測
        // toArray()/fromArray() 直接串接測不出這個問題——中間必須真的走一趟
        // json_encode／json_decode 才會讓浮點數退化成整數。
        $data = new OrderInventoryData(annualRevenue: [
            ['fiscal_year' => 2025, 'revenue' => 500.0],
        ]);

        $decoded = json_decode(json_encode($data->toArray()), true);
        $restored = OrderInventoryData::fromArray($decoded);

        $this->assertIsFloat($restored->annualRevenue[0]['revenue']);
        $this->assertSame(500.0, $restored->annualRevenue[0]['revenue']);
    }

    public function test_fiscal_fields_default_to_null_for_legacy_data_without_the_keys(): void
    {
        // order_inventory 是 JSON 欄位，正式站有這個 task 之前存的舊資料，
        // 沒有 fiscal_year／fiscal_period 這兩個鍵。fromArray() 不得因此拋錯。
        $legacy = new QuarterlyFinancials(period: '2026Q2', inventories: 600.0);
        $legacyArray = $legacy->toArray();
        unset($legacyArray['fiscal_year'], $legacyArray['fiscal_period']);

        $restored = QuarterlyFinancials::fromArray($legacyArray);

        $this->assertNull($restored->fiscalYear);
        $this->assertNull($restored->fiscalPeriod);
        $this->assertSame(600.0, $restored->inventories);
    }
}
