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
        $this->assertNull($result['note'], 'suited 不需要提醒文案');
    }

    #[Test]
    public function taiwan_broad_electronics_category_is_suited(): void
    {
        // TaiwanIndustryResolver::BROAD_CATEGORIES 在沒有更細分類時仍會採用
        // 「電子工業」這個上位分類（實測 3019 同時掛「光電業」與「電子工業」），
        // 它就是電子業，不該落到 unknown。
        $result = (new OrderInventoryIndustryPolicy)->evaluate($this->data('tw', '電子工業'));

        $this->assertSame('suited', $result['bucket']);
        $this->assertTrue($result['applicable']);
    }

    #[Test]
    public function an_unmatched_taiwan_industry_string_stays_applicable(): void
    {
        // 與 an_unknown_taiwan_industry_stays_applicable 不同：這裡餵的是非空、
        // 但 57 類裡對不到任何一桶的產業字串（例如上位分類「其他」），
        // 不是 null。兩條路徑（industry === null vs 對不到任何桶）分屬不同分支，
        // 必須各自有測試。
        $result = (new OrderInventoryIndustryPolicy)->evaluate($this->data('tw', '其他'));

        $this->assertSame('unknown', $result['bucket']);
        $this->assertTrue($result['applicable'], '對不到任何一桶不能當成不適用，只是分類資訊不足');
    }

    #[Test]
    public function not_applicable_bucket_wins_when_bucket_names_overlap(): void
    {
        // 現有 29 個正式設定值彼此不包含（29×29 全配對 str_contains 為零命中），
        // 順序在今天不可觀測，必須自己造一組跨桶互相包含的桶名才測得到。
        config([
            'order_inventory.industry.not_applicable' => ['金融'],
            'order_inventory.industry.adjust' => [],
            'order_inventory.industry.suited' => ['金融科技'],
        ]);

        $result = (new OrderInventoryIndustryPolicy)->evaluate($this->data('tw', '金融科技'));

        $this->assertSame(
            'not_applicable',
            $result['bucket'],
            'not_applicable 要先於 suited 比對，較嚴格（排除）的桶勝出',
        );
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
        $this->assertNotNull($result['note'], '兩市場的 not_applicable 都該有 note，不能一個有一個沒有');
    }

    #[Test]
    public function taiwan_financials_match_both_the_finmind_variant_and_the_config_core_word(): void
    {
        // matches() 是 str_contains($industry, $needle)：config 值必須是
        // FinMind 字串的子字串，所以只涵蓋「FinMind 字串比設定值長」的情形。
        // 設定檔已把 '金融保險業' 縮成核心詞 '金融保險'，兩種寫法都要命中。
        $longVariant = (new OrderInventoryIndustryPolicy)->evaluate($this->data('tw', '金融保險業'));
        $shortVariant = (new OrderInventoryIndustryPolicy)->evaluate($this->data('tw', '金融保險'));

        $this->assertSame('not_applicable', $longVariant['bucket'], 'FinMind 回較長變體要命中');
        $this->assertSame('not_applicable', $shortVariant['bucket'], '設定值本身（較短核心詞）也要命中');
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
        $this->assertNotNull($result['note'], '兩市場的 not_applicable 都該有 note，不能一個有一個沒有');
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
        $this->assertNull($result['note'], 'suited 不需要提醒文案');
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

    #[Test]
    public function it_cannot_judge_a_us_company_with_zero_revenue(): void
    {
        // PHP 8 對 0.0 做除法會拋 DivisionByZeroError（不是回 INF），而
        // revenue: 0.0 是合法輸入（QuarterlyFinancials docblock：0 是合法的財報
        // 數字，不可用來表示無資料）。這條守衛擋的正是「不能讓它跑到除法那行」。
        $result = (new OrderInventoryIndustryPolicy)->evaluate(
            $this->data('us', null, ['revenue' => 0.0, 'inventories' => 350.0]),
        );

        $this->assertSame('unknown', $result['bucket'], 'revenue 恰為 0 不能斷言不適用，也不能拋例外');
        $this->assertTrue($result['applicable']);
    }
}
