<?php

namespace Tests\Unit\FinancialStatements;

use App\Services\FinancialStatements\Sec\SecValueExtractor;
use Tests\Support\SecFixture;
use Tests\TestCase;

class SecValueExtractorTest extends TestCase
{
    private function extractor(): SecValueExtractor
    {
        return new SecValueExtractor;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rowsByTag
     * @param  array<string, list<array<string, mixed>>>  $sharesByTag
     */
    private function facts(array $rowsByTag, array $sharesByTag = []): array
    {
        $facts = [];

        foreach ($rowsByTag as $tag => $rows) {
            $facts[$tag] = ['units' => ['USD' => $rows]];
        }

        foreach ($sharesByTag as $tag => $rows) {
            $facts[$tag] = ['units' => ['USD/shares' => $rows]];
        }

        return ['cik' => 1, 'entityName' => 'T', 'facts' => ['us-gaap' => $facts]];
    }

    private function row(string $start, string $end, float $val, array $extra = []): array
    {
        return array_merge([
            'start' => $start, 'end' => $end, 'val' => $val,
            'form' => '10-Q', 'filed' => '2025-05-01', 'accn' => 'a1',
        ], $extra);
    }

    public function test_falls_back_per_period_not_per_tag(): void
    {
        // 第一順位 tag 只有 Q1 有值；Q2 必須往下一個 tag 找**該期間**。
        // 「這個 tag 出現過就定案」的實作會讓 Q2 的營收變成 null。
        $facts = $this->facts([
            'RevenueFromContractWithCustomerIncludingAssessedTax' => [
                $this->row('2025-01-01', '2025-03-31', 100),
            ],
            'Revenues' => [
                $this->row('2025-04-01', '2025-06-30', 200),
            ],
        ]);

        $q2 = $this->extractor()->forPeriod($facts, '2025-04-01', '2025-06-30');

        $this->assertSame(200.0, $q2['values']['revenue']);
    }

    /**
     * 逐 period fallback 的另一面：同一期間**兩個**不同優先序的 tag 都有值時，
     * 必須取第一順位（config 清單順序較前）的值，不是「有值就用」或取最後一個。
     *
     * 若既有測試只驗「第一順位缺席時往下找」，把優先序顛倒（或直接取清單最後一個）
     * 不會讓任何測試變紅——因為那些測試裡每個期間只有一個 tag 有值。這裡刻意讓
     * 兩個 tag 在同一期間都有值，兩個值不同，才能把優先序本身釘住。
     */
    public function test_tag_priority_order_wins_when_both_tags_have_values_for_the_same_period(): void
    {
        $facts = $this->facts([
            // config 的 revenue 優先序：IncludingAssessedTax 在前，Revenues 在後。
            'RevenueFromContractWithCustomerIncludingAssessedTax' => [
                $this->row('2025-01-01', '2025-03-31', 100),
            ],
            'Revenues' => [
                $this->row('2025-01-01', '2025-03-31', 999),
            ],
        ]);

        $q1 = $this->extractor()->forPeriod($facts, '2025-01-01', '2025-03-31');

        $this->assertSame(100.0, $q1['values']['revenue'], '第一順位 tag 有值時必須贏，不能被後順位覆蓋');
    }

    public function test_takes_the_latest_filed_version_of_a_period(): void
    {
        $facts = $this->facts(['Revenues' => [
            $this->row('2025-01-01', '2025-03-31', 100, ['filed' => '2025-05-01', 'accn' => 'old']),
            $this->row('2025-01-01', '2025-03-31', 150, ['filed' => '2026-02-01', 'accn' => 'new']),
        ]]);

        $q1 = $this->extractor()->forPeriod($facts, '2025-01-01', '2025-03-31');

        $this->assertSame(150.0, $q1['values']['revenue']);
        $this->assertSame('new', $q1['accns']['income']);
    }

    public function test_reads_eps_from_the_per_share_unit(): void
    {
        // EPS 的單位鍵是 USD/shares；只讀 units.USD 會完全讀不到。
        $facts = $this->facts([], ['EarningsPerShareBasic' => [
            $this->row('2025-01-01', '2025-03-31', 1.25),
        ]]);

        $q1 = $this->extractor()->forPeriod($facts, '2025-01-01', '2025-03-31');

        $this->assertSame(1.25, $q1['values']['eps_basic']);
    }

    public function test_capex_is_normalised_to_a_negative_outflow(): void
    {
        // SEC 的 PaymentsToAcquire... 是正值（付出的金額）；本表統一存負值代表流出。
        // 既有 order_inventory 的投影取 abs()，與本表不共用契約。
        $facts = $this->facts(['PaymentsToAcquirePropertyPlantAndEquipment' => [
            $this->row('2025-01-01', '2025-03-31', 5000),
        ]]);

        $q1 = $this->extractor()->forPeriod($facts, '2025-01-01', '2025-03-31');

        $this->assertSame(-5000.0, $q1['values']['capex']);
    }

    /**
     * 符號規則是「正值才翻負」，不是「一律翻負」。用正值輸入無法區分這兩種實作
     * （正值翻負後兩者結果相同）：正值 5000 一律翻負也會得到 -5000。
     * 這裡用已經是負值的輸入，驗證它**不會**被再次翻成正值。
     */
    public function test_capex_already_negative_is_not_flipped_positive(): void
    {
        $facts = $this->facts(['PaymentsToAcquirePropertyPlantAndEquipment' => [
            $this->row('2025-01-01', '2025-03-31', -3000),
        ]]);

        $q1 = $this->extractor()->forPeriod($facts, '2025-01-01', '2025-03-31');

        $this->assertSame(-3000.0, $q1['values']['capex']);
    }

    public function test_instant_fields_match_on_end_date_only(): void
    {
        $facts = $this->facts(['Assets' => [
            ['end' => '2025-03-31', 'val' => 9000, 'form' => '10-Q', 'filed' => '2025-05-01', 'accn' => 'b1'],
        ]]);

        $bs = $this->extractor()->forInstant($facts, '2025-03-31');

        $this->assertSame(9000.0, $bs['values']['total_assets']);
        $this->assertSame('b1', $bs['accn']);
    }

    public function test_instant_tolerates_three_days(): void
    {
        $facts = $this->facts(['Assets' => [
            ['end' => '2025-04-02', 'val' => 9000, 'form' => '10-Q', 'filed' => '2025-05-01', 'accn' => 'b1'],
        ]]);

        $this->assertSame(9000.0, $this->extractor()->forInstant($facts, '2025-03-31')['values']['total_assets']);
    }

    public function test_instant_beyond_tolerance_is_discarded_not_guessed(): void
    {
        $facts = $this->facts(['Assets' => [
            ['end' => '2025-04-20', 'val' => 9000, 'form' => '10-Q', 'filed' => '2025-05-01', 'accn' => 'b1'],
        ]]);

        $this->assertNull($this->extractor()->forInstant($facts, '2025-03-31')['values']['total_assets']);
    }

    /**
     * 容忍窗是閉區間：恰好 3 天要接受，4 天要拒絕。用 SecQuarterChainTest 同款
     * 邊界測試手法，把「±3 天」這個規則本身釘住，而不是只驗「差很多」與「差很少」。
     */
    public function test_instant_tolerance_boundary_is_inclusive(): void
    {
        $accepted = $this->facts(['Assets' => [
            ['end' => '2025-04-03', 'val' => 9000, 'form' => '10-Q', 'filed' => '2025-05-01', 'accn' => 'b1'],
        ]]);
        $rejected = $this->facts(['Assets' => [
            ['end' => '2025-04-04', 'val' => 9000, 'form' => '10-Q', 'filed' => '2025-05-01', 'accn' => 'b1'],
        ]]);

        $this->assertSame(
            9000.0,
            $this->extractor()->forInstant($accepted, '2025-03-31')['values']['total_assets'],
            '恰好 3 天應被接受'
        );
        $this->assertNull(
            $this->extractor()->forInstant($rejected, '2025-03-31')['values']['total_assets'],
            '4 天應被容忍窗拒絕'
        );
    }

    public function test_zero_is_a_value_not_a_missing_field(): void
    {
        $facts = $this->facts(['Revenues' => [
            $this->row('2025-01-01', '2025-03-31', 0),
        ]]);

        $this->assertSame(0.0, $this->extractor()->forPeriod($facts, '2025-01-01', '2025-03-31')['values']['revenue']);
    }

    /**
     * accns 的鍵依科目所屬報表分流：income 與 cashflow 不能混用同一個鍵，
     * 否則 normalizer 之後沒辦法分別標注兩張表各自的來源 accession。
     */
    public function test_cashflow_field_accn_is_recorded_under_the_cashflow_key_not_income(): void
    {
        $facts = $this->facts(['NetCashProvidedByUsedInOperatingActivities' => [
            $this->row('2025-01-01', '2025-03-31', 4000, ['accn' => 'cf1']),
        ]]);

        $q1 = $this->extractor()->forPeriod($facts, '2025-01-01', '2025-03-31');

        $this->assertSame(4000.0, $q1['values']['operating_cash_flow']);
        $this->assertSame('cf1', $q1['accns']['cashflow']);
        $this->assertNull($q1['accns']['income'], '現金流科目不該污染 income 的 accn');
    }

    public function test_rgti_2026q2_matches_google_finance(): void
    {
        $q = $this->extractor()->forPeriod(SecFixture::load('rgti'), '2026-04-01', '2026-06-30');

        $this->assertSame(5138000.0, $q['values']['revenue'], 'Google Finance 顯示 513.80 萬');
        $this->assertSame(2950000.0, $q['values']['cost_of_revenue'], 'Google Finance 顯示 295.00 萬');
    }
}
