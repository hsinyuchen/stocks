<?php

namespace Tests\Unit\FinancialStatements;

use App\Enums\DerivationKind;
use App\Services\FinancialStatements\Sec\SecQuarterDeriver;
use Tests\Support\SecFixture;
use Tests\TestCase;

class SecQuarterDeriverTest extends TestCase
{
    private function deriver(): SecQuarterDeriver
    {
        return new SecQuarterDeriver;
    }

    public function test_derives_q4_by_subtracting_three_quarters(): void
    {
        $result = $this->deriver()->deriveIncome(
            ['revenue' => 1000.0, 'net_income' => 100.0],
            [
                ['revenue' => 200.0, 'net_income' => 20.0],
                ['revenue' => 300.0, 'net_income' => 30.0],
                ['revenue' => 250.0, 'net_income' => 25.0],
            ]
        );

        $this->assertSame(250.0, $result['values']['revenue']);
        $this->assertSame(25.0, $result['values']['net_income']);
        $this->assertSame(DerivationKind::Derived, $result['kind']);
    }

    public function test_a_missing_quarter_only_nulls_that_field(): void
    {
        // 研發費用某季未單獨列示，不得連坐整張損益表。
        $result = $this->deriver()->deriveIncome(
            ['revenue' => 1000.0, 'research_development' => 400.0],
            [
                ['revenue' => 200.0, 'research_development' => 100.0],
                ['revenue' => 300.0, 'research_development' => null],
                ['revenue' => 250.0, 'research_development' => 100.0],
            ]
        );

        $this->assertSame(250.0, $result['values']['revenue']);
        $this->assertNull($result['values']['research_development']);
    }

    public function test_annual_missing_means_that_field_is_null(): void
    {
        $result = $this->deriver()->deriveIncome(
            ['revenue' => null],
            [['revenue' => 200.0], ['revenue' => 300.0], ['revenue' => 250.0]]
        );

        $this->assertNull($result['values']['revenue']);
    }

    public function test_eps_is_never_derived(): void
    {
        // 年度 EPS 與各季 EPS 用不同的加權平均股數，相減不具數學等價性。
        $result = $this->deriver()->deriveIncome(
            ['revenue' => 1000.0, 'eps_basic' => 4.0],
            [
                ['revenue' => 200.0, 'eps_basic' => 1.0],
                ['revenue' => 300.0, 'eps_basic' => 1.0],
                ['revenue' => 250.0, 'eps_basic' => 1.0],
            ]
        );

        $this->assertArrayNotHasKey('eps_basic', $result['values']);
    }

    public function test_kind_is_mixed_when_some_fields_are_direct(): void
    {
        // deriveIncome 收到已有直接值的科目時，該科目保留、標 mixed。
        $result = $this->deriver()->deriveIncome(
            ['revenue' => 1000.0, 'net_income' => 100.0],
            [
                ['revenue' => 200.0, 'net_income' => 20.0],
                ['revenue' => 300.0, 'net_income' => 30.0],
                ['revenue' => 250.0, 'net_income' => 25.0],
            ],
            direct: ['net_income' => 99.0],
        );

        $this->assertSame(250.0, $result['values']['revenue'], '沒有直接值的科目照樣推導');
        $this->assertSame(99.0, $result['values']['net_income'], '有直接值就用直接值');
        $this->assertSame(DerivationKind::Mixed, $result['kind']);
    }

    public function test_kind_is_direct_when_everything_has_a_direct_value(): void
    {
        $result = $this->deriver()->deriveIncome(
            ['revenue' => 1000.0],
            [['revenue' => 200.0], ['revenue' => 300.0], ['revenue' => 250.0]],
            direct: ['revenue' => 250.0],
        );

        $this->assertSame(DerivationKind::Direct, $result['kind']);
    }

    public function test_detects_a_restated_period(): void
    {
        $facts = ['facts' => ['us-gaap' => ['Revenues' => ['units' => ['USD' => [
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 100,
                'filed' => '2025-02-01', 'accn' => 'a1', 'form' => '10-K'],
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 150,
                'filed' => '2026-02-01', 'accn' => 'a2', 'form' => '10-K'],
        ]]]]]];

        $this->assertSame('2026-02-01', $this->deriver()->restatedAt($facts, '2024-01-01', '2024-12-31'));
    }

    public function test_same_value_in_two_filings_is_not_a_restatement(): void
    {
        $facts = ['facts' => ['us-gaap' => ['Revenues' => ['units' => ['USD' => [
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 100,
                'filed' => '2025-02-01', 'accn' => 'a1', 'form' => '10-K'],
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 100,
                'filed' => '2026-02-01', 'accn' => 'a2', 'form' => '10-K'],
        ]]]]]];

        $this->assertNull($this->deriver()->restatedAt($facts, '2024-01-01', '2024-12-31'));
    }

    public function test_three_separate_ten_qs_alone_do_not_make_it_mixed(): void
    {
        // Q1/Q2/Q3 本來就分屬三份 10-Q。「accession 不同就算」的判準會讓
        // 每一次差分都標 true，等於沒有資訊。
        $rows = [];

        foreach ([['2025-01-01', '2025-03-31', '2025-05-01'],
            ['2025-04-01', '2025-06-30', '2025-08-01'],
            ['2025-07-01', '2025-09-30', '2025-11-01']] as $i => [$s, $e, $f]) {
            $rows[] = ['start' => $s, 'end' => $e, 'val' => 10, 'filed' => $f,
                'accn' => "q{$i}", 'form' => '10-Q'];
        }

        $facts = ['facts' => ['us-gaap' => ['Revenues' => ['units' => ['USD' => $rows]]]]];

        $periods = [
            ['start' => '2025-01-01', 'end' => '2025-03-31'],
            ['start' => '2025-04-01', 'end' => '2025-06-30'],
            ['start' => '2025-07-01', 'end' => '2025-09-30'],
        ];

        $this->assertFalse($this->deriver()->isMixed($facts, $periods));
    }

    public function test_restated_year_with_unrevised_quarters_is_mixed(): void
    {
        // 這是美股最常見也最危險的形狀：後續 10-K 追溯重編全年，
        // 但**不回頭補發前三季的 10-Q/A**。兩個錯誤判準在這裡都會回 false。
        $rows = [
            // 前三季各只有一個原始版本
            ['start' => '2024-01-01', 'end' => '2024-03-31', 'val' => 10,
                'filed' => '2024-05-01', 'accn' => 'q1', 'form' => '10-Q'],
            ['start' => '2024-04-01', 'end' => '2024-06-30', 'val' => 10,
                'filed' => '2024-08-01', 'accn' => 'q2', 'form' => '10-Q'],
            ['start' => '2024-07-01', 'end' => '2024-09-30', 'val' => 10,
                'filed' => '2024-11-01', 'accn' => 'q3', 'form' => '10-Q'],
            // 全年有兩個版本，第二個是 2026 年的追溯重編
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 50,
                'filed' => '2025-02-01', 'accn' => 'k1', 'form' => '10-K'],
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 60,
                'filed' => '2026-02-01', 'accn' => 'k2', 'form' => '10-K'],
        ];

        $facts = ['facts' => ['us-gaap' => ['Revenues' => ['units' => ['USD' => $rows]]]]];

        $periods = [
            ['start' => '2024-01-01', 'end' => '2024-03-31'],
            ['start' => '2024-04-01', 'end' => '2024-06-30'],
            ['start' => '2024-07-01', 'end' => '2024-09-30'],
            ['start' => '2024-01-01', 'end' => '2024-12-31'],
        ];

        $this->assertTrue(
            $this->deriver()->isMixed($facts, $periods),
            '重編後全年減未重編前三季是混版垃圾，必須標記'
        );
    }

    public function test_aapl_fy2008_is_detected_as_restated(): void
    {
        $at = $this->deriver()->restatedAt(SecFixture::load('aapl'), '2007-09-30', '2008-09-27');

        $this->assertNotNull($at, 'AAPL FY2008 的營收在 2010 年被追溯重編');
    }

    // --- 以下為 self-review 補測試：brief 給定的 11 個測試中，有幾條規則分支
    // 只在特定資料形狀下才能與「錯誤但恆真/恆假」的判準區分開來，補齊避免變異矇混。

    public function test_direct_quarter_priority_would_be_defeated_by_always_deriving(): void
    {
        // 「有直接 Q4 就用，其餘科目照常推導」是逐科目規則，brief 用 test_kind_is_direct_...
        // 只驗了 kind，這裡額外釘住數值本身：若把 direct 參數整個忽略、一律相減，
        // revenue 會算成 200.0（1000 - 200 - 300 - 300）而非直接值 999.0，
        // 刻意讓兩者算出的數字不同，才測得出來。
        $result = $this->deriver()->deriveIncome(
            ['revenue' => 1000.0],
            [['revenue' => 200.0], ['revenue' => 300.0], ['revenue' => 300.0]],
            direct: ['revenue' => 999.0],
        );

        $this->assertSame(999.0, $result['values']['revenue'], '有直接值必須原樣採用，不可被推導值覆蓋');
    }

    public function test_restated_at_ignores_periods_with_only_one_filing(): void
    {
        // 只有單一 accn 時不構成重編判斷的必要條件（count < 2），
        // 防止把「accession 不同就算」的錯誤判準悄悄搬進 restatedAt 本身。
        $facts = ['facts' => ['us-gaap' => ['Revenues' => ['units' => ['USD' => [
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 100,
                'filed' => '2025-02-01', 'accn' => 'a1', 'form' => '10-K'],
        ]]]]]];

        $this->assertNull($this->deriver()->restatedAt($facts, '2024-01-01', '2024-12-31'));
    }

    // --- 以下補測試：審查發現 subtract() 的 count($quarters) !== 3 守衛完全沒有
    // 變異覆蓋（拿掉它 14 條既有測試全部維持綠燈），單獨釘住這道守衛。

    public function test_quarter_count_guard_rejects_two_quarters(): void
    {
        // 兩季相減會把兩季的量誤算成一整季，數字看似「有值」但語意錯誤，
        // 守衛必須攔在「算不算得出值」之前直接回 null，而非硬算出一個誤導性數字。
        $result = $this->deriver()->deriveIncome(
            ['revenue' => 1000.0],
            [
                ['revenue' => 200.0],
                ['revenue' => 300.0],
            ]
        );

        $this->assertNull($result['values']['revenue']);
    }

    public function test_quarter_count_guard_rejects_four_quarters(): void
    {
        // 四季相減等於多扣一次，同樣不具數學意義；守衛須同時擋「多於」與「少於」三季兩側。
        $result = $this->deriver()->deriveIncome(
            ['revenue' => 1000.0],
            [
                ['revenue' => 200.0],
                ['revenue' => 300.0],
                ['revenue' => 250.0],
                ['revenue' => 100.0],
            ]
        );

        $this->assertNull($result['values']['revenue']);
    }

    public function test_quarter_count_guard_allows_exactly_three_quarters(): void
    {
        // 對照組：同一組 annual／revenue 資料，唯一變因是季數改回 3，
        // 藉此證明上面兩條測試的 null 是季數守衛擋下的，不是 annual 缺值之類的其他條件順手擋掉。
        $result = $this->deriver()->deriveIncome(
            ['revenue' => 1000.0],
            [
                ['revenue' => 200.0],
                ['revenue' => 300.0],
                ['revenue' => 250.0],
            ]
        );

        $this->assertSame(250.0, $result['values']['revenue']);
    }

    public function test_restatement_of_one_tag_is_not_masked_by_an_unrestated_tag_sharing_the_same_accn(): void
    {
        // 迴歸測試：實作最初把所有 us-gaap 科目的列混進同一個 accn→val map 比對，
        // 導致同一份 filing 底下、沒被重編的科目（這裡是 ShareBasedCompensation，
        // 三次申報都填 5）把已被重編的 Revenues（100 → 200）蓋掉，回傳 null。
        // 真實案例見 AAPL FY2008：SalesRevenueNet 被 ShareBasedCompensation 蓋掉。
        $facts = ['facts' => ['us-gaap' => [
            'Revenues' => ['units' => ['USD' => [
                ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 100,
                    'filed' => '2025-02-01', 'accn' => 'a1', 'form' => '10-K'],
                ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 200,
                    'filed' => '2026-02-01', 'accn' => 'a2', 'form' => '10-K'],
            ]]],
            'ShareBasedCompensation' => ['units' => ['USD' => [
                ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 5,
                    'filed' => '2025-02-01', 'accn' => 'a1', 'form' => '10-K'],
                ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 5,
                    'filed' => '2026-02-01', 'accn' => 'a2', 'form' => '10-K'],
            ]]],
        ]]];

        $this->assertSame(
            '2026-02-01',
            $this->deriver()->restatedAt($facts, '2024-01-01', '2024-12-31'),
            '不相干科目共用 accn 不得掩蓋另一科目真正的重編'
        );
    }

    // --- I4：重編偵測掃全部 tag，與三表無關的科目被重編也會誤標 ---

    public function test_restatement_of_an_unrelated_tag_does_not_mark_the_period_as_restated(): void
    {
        // 審查用真實未裁切的 AAPL companyfacts 對照跑過同一支 normalizer：
        // 裁切後的 fixture（只留 sec_tags∪sec_eps_tags∪anchor_tags，46 個）
        // 算出 restatement_mixed=5／90 期，真實完整檔案（503 個 tag）算出
        // 31／90 期——任何一個與三表無關的 tag 被重編都會讓它誤報。
        //
        // 這裡合成一個「與三表無關的 tag（AccruedLiabilitiesCurrent 未列在
        // sec_tags／sec_eps_tags 裡）被重編、三表用到的 Revenues 完全沒被
        // 重編」的 companyfacts，斷言 restatedAt() 必須忽略前者、回 null。
        $facts = ['facts' => ['us-gaap' => [
            'Revenues' => ['units' => ['USD' => [
                ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 100,
                    'filed' => '2025-02-01', 'accn' => 'a1', 'form' => '10-K'],
            ]]],
            'SomeUnrelatedTagNotInAnyStatement' => ['units' => ['USD' => [
                ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 10,
                    'filed' => '2025-02-01', 'accn' => 'a1', 'form' => '10-K'],
                ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 20,
                    'filed' => '2026-02-01', 'accn' => 'a2', 'form' => '10-K'],
            ]]],
        ]]];

        $this->assertNull(
            $this->deriver()->restatedAt($facts, '2024-01-01', '2024-12-31'),
            '與三表無關的科目被重編不該讓這個期間被標成重編'
        );

        $periods = [['start' => '2024-01-01', 'end' => '2024-12-31']];
        $this->assertFalse(
            $this->deriver()->isMixed($facts, $periods),
            '同一個原因：isMixed() 也不該被無關科目的重編誤觸發'
        );
    }

    public function test_derived_quarters_never_carry_eps(): void
    {
        // 每股金額不可加減（期間內股數會變），所以 EPS 刻意不在 income_fields 裡，
        // 推導出來的 Q4 恆為 null、畫面顯示「—」。台股年度列走同一條規則
        // （見 TaiwanAnnualDeriver）。沒有這條測試，日後有人為了「補齊欄位」
        // 把 eps_basic 加進 income_fields 不會有任何訊號。
        $result = $this->deriver()->deriveIncome(
            ['revenue' => 1000.0, 'eps_basic' => 4.0, 'eps_diluted' => 3.8],
            [
                ['revenue' => 200.0, 'eps_basic' => 1.0, 'eps_diluted' => 0.9],
                ['revenue' => 300.0, 'eps_basic' => 1.5, 'eps_diluted' => 1.4],
                ['revenue' => 250.0, 'eps_basic' => 1.0, 'eps_diluted' => 0.9],
            ]
        );

        $this->assertSame(250.0, $result['values']['revenue'], '損益科目照常推導');
        $this->assertArrayNotHasKey('eps_basic', $result['values']);
        $this->assertArrayNotHasKey('eps_diluted', $result['values']);
    }
}
