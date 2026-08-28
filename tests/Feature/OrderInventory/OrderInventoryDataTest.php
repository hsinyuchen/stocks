<?php

namespace Tests\Feature\OrderInventory;

use App\Data\OrderInventoryData;
use Tests\TestCase;

class OrderInventoryDataTest extends TestCase
{
    public function test_has_any_still_means_quarters_only(): void
    {
        $monthlyOnly = new OrderInventoryData(
            quarters: [],
            monthlyRevenue: [['month' => '2026-07-01', 'revenue' => 100.0, 'yoy' => 0.1]],
            market: 'tw',
        );

        // hasAny() 決定訂單庫存評級是否棄權（OrderInventoryAssessor），
        // 評級需要近 8 季，只有月營收時必須維持棄權。放寬它會讓沒有季報的
        // 個股被評出一個沒有依據的等級。
        $this->assertFalse($monthlyOnly->hasAny());
    }

    public function test_has_revenue_series_reports_monthly_revenue(): void
    {
        $monthlyOnly = new OrderInventoryData(
            quarters: [],
            monthlyRevenue: [['month' => '2026-07-01', 'revenue' => 100.0, 'yoy' => 0.1]],
            market: 'tw',
        );

        $this->assertTrue($monthlyOnly->hasRevenueSeries());
        $this->assertFalse(OrderInventoryData::empty()->hasRevenueSeries());
    }

    public function test_has_annual_revenue_reports_annual_revenue_only(): void
    {
        // 美股「只有年報」救援分支：季度與月營收都缺席，只有年營收。
        $annualOnly = new OrderInventoryData(
            quarters: [],
            monthlyRevenue: [],
            market: 'us',
            annualRevenue: [['fiscal_year' => 2026, 'revenue' => 215938000000.0]],
        );

        $this->assertTrue($annualOnly->hasAnnualRevenue());
        $this->assertFalse($annualOnly->hasAny(), 'hasAny() 只看季度，不能被年營收頂替');
        $this->assertFalse(OrderInventoryData::empty()->hasAnnualRevenue());
    }
}
