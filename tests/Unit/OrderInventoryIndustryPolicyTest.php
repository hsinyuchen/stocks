<?php

namespace Tests\Unit;

use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Services\Fundamentals\OrderInventoryIndustryPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryIndustryPolicyTest extends TestCase
{
    private function data(string $market, ?string $industry, array $latest = []): OrderInventoryData
    {
        return new OrderInventoryData(
            quarters: [new QuarterlyFinancials(...array_merge([
                'period' => '2026Q2',
                'revenue' => 1000.0,
                'inventories' => 350.0,
            ], $latest))],
            market: $market,
            industry: $industry,
        );
    }

    #[Test]
    public function taiwan_electronics_industries_are_suited(): void
    {
        $result = (new OrderInventoryIndustryPolicy)->evaluate($this->data('tw', '半導體業'));

        $this->assertSame('suited', $result['bucket']);
        $this->assertTrue($result['applicable']);
    }

    #[Test]
    public function taiwan_distributors_are_applicable_but_need_adjusted_reading(): void
    {
        $result = (new OrderInventoryIndustryPolicy)->evaluate($this->data('tw', '貿易百貨'));

        $this->assertSame('adjust', $result['bucket']);
        $this->assertTrue($result['applicable'], '需調整判讀仍要評級，只是文案要提醒');
        $this->assertNotNull($result['note']);
    }

    #[Test]
    public function taiwan_financials_are_not_applicable(): void
    {
        $result = (new OrderInventoryIndustryPolicy)->evaluate($this->data('tw', '金融保險業'));

        $this->assertSame('not_applicable', $result['bucket']);
        $this->assertFalse($result['applicable']);
    }

    #[Test]
    public function an_unknown_taiwan_industry_stays_applicable(): void
    {
        $result = (new OrderInventoryIndustryPolicy)->evaluate($this->data('tw', null));

        $this->assertSame('unknown', $result['bucket']);
        $this->assertTrue(
            $result['applicable'],
            '產業解析失敗不該讓整批股票停止評級——未知不等於不適用',
        );
        $this->assertNotNull($result['note']);
    }

    #[Test]
    public function us_companies_without_inventory_are_not_applicable(): void
    {
        $result = (new OrderInventoryIndustryPolicy)->evaluate(
            $this->data('us', null, ['inventories' => null]),
        );

        $this->assertSame('not_applicable', $result['bucket']);
        $this->assertFalse($result['applicable'], '銀行與純軟體不報存貨，本框架沒有進銷存循環可談');
    }

    #[Test]
    public function us_companies_with_negligible_inventory_are_not_applicable(): void
    {
        // 存貨 / 季營收 = 20 / 1000 = 0.02，低於 0.05 門檻。
        $result = (new OrderInventoryIndustryPolicy)->evaluate(
            $this->data('us', null, ['inventories' => 20.0]),
        );

        $this->assertSame('not_applicable', $result['bucket']);
    }

    #[Test]
    public function us_companies_with_meaningful_inventory_are_suited(): void
    {
        $result = (new OrderInventoryIndustryPolicy)->evaluate(
            $this->data('us', null, ['inventories' => 350.0]),
        );

        $this->assertSame('suited', $result['bucket']);
        $this->assertTrue($result['applicable']);
    }

    #[Test]
    public function the_us_inventory_share_boundary_is_inclusive(): void
    {
        $threshold = (float) config('order_inventory.industry.us_min_inventory_to_revenue');

        $result = (new OrderInventoryIndustryPolicy)->evaluate(
            $this->data('us', null, ['inventories' => 1000.0 * $threshold]),
        );

        $this->assertSame(
            'suited',
            $result['bucket'],
            '恰好等於門檻算通過；此斷言把 >= 與 > 的差別釘住',
        );
    }

    #[Test]
    public function it_cannot_judge_a_us_company_with_no_revenue(): void
    {
        $result = (new OrderInventoryIndustryPolicy)->evaluate(
            $this->data('us', null, ['revenue' => null, 'inventories' => 350.0]),
        );

        $this->assertSame('unknown', $result['bucket'], '沒有營收就算不出佔比，不能斷言不適用');
        $this->assertTrue($result['applicable']);
    }
}
