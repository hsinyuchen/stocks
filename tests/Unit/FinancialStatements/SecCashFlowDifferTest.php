<?php

namespace Tests\Unit\FinancialStatements;

use App\Data\FiscalYearBoundary;
use App\Enums\DerivationKind;
use App\Enums\PeriodType;
use App\Services\FinancialStatements\Sec\SecCashFlowDiffer;
use Tests\Support\SecFixture;
use Tests\TestCase;

class SecCashFlowDifferTest extends TestCase
{
    private function differ(): SecCashFlowDiffer
    {
        return new SecCashFlowDiffer;
    }

    /**
     * @param  list<array{0:string,1:string,2:float}>  $ytd  [start, end, val]
     */
    private function facts(array $ytd, string $tag = 'NetCashProvidedByUsedInOperatingActivities'): array
    {
        $rows = [];

        foreach ($ytd as [$s, $e, $v]) {
            $rows[] = ['start' => $s, 'end' => $e, 'val' => $v,
                'form' => '10-Q', 'filed' => $e, 'accn' => $e];
        }

        return ['facts' => ['us-gaap' => [$tag => ['units' => ['USD' => $rows]]]]];
    }

    private function year(): FiscalYearBoundary
    {
        return new FiscalYearBoundary(2025, '2025-01-01', '2025-12-31', PeriodType::Annual);
    }

    /** @return list<array{start:string,end:string}> */
    private function threeQuarters(): array
    {
        return [
            ['start' => '2025-01-01', 'end' => '2025-03-31'],
            ['start' => '2025-04-01', 'end' => '2025-06-30'],
            ['start' => '2025-07-01', 'end' => '2025-09-30'],
        ];
    }

    public function test_first_quarter_is_taken_directly(): void
    {
        $facts = $this->facts([['2025-01-01', '2025-03-31', -100.0]]);

        $q1 = $this->differ()->forQuarter($facts, $this->year(), $this->threeQuarters(), 0);

        $this->assertSame(-100.0, $q1['values']['operating_cash_flow']);
        $this->assertSame(DerivationKind::Direct, $q1['kind']);
    }

    public function test_second_quarter_is_the_difference_of_two_ytd_rows(): void
    {
        $facts = $this->facts([
            ['2025-01-01', '2025-03-31', -100.0],
            ['2025-01-01', '2025-06-30', -250.0],
        ]);

        $q2 = $this->differ()->forQuarter($facts, $this->year(), $this->threeQuarters(), 1);

        $this->assertSame(-150.0, $q2['values']['operating_cash_flow']);
        $this->assertSame(DerivationKind::Derived, $q2['kind']);
    }

    public function test_missing_previous_ytd_leaves_null_never_crosses_periods(): void
    {
        // 只有 Q1 與 Q3 的 YTD。Q3 不得用 Q1 相減——那會得到 6 個月的數字。
        $facts = $this->facts([
            ['2025-01-01', '2025-03-31', -100.0],
            ['2025-01-01', '2025-09-30', -400.0],
        ]);

        $q3 = $this->differ()->forQuarter($facts, $this->year(), $this->threeQuarters(), 2);

        $this->assertNull($q3['values']['operating_cash_flow']);
    }

    public function test_derived_fourth_quarter_anchors_ytd_to_the_fiscal_year_end(): void
    {
        // 只鏈出三季時沒有「第 4 季的 end」可用，YTD 直接錨定 fyEnd。
        $facts = $this->facts([
            ['2025-01-01', '2025-09-30', -400.0],
            ['2025-01-01', '2025-12-31', -600.0],
        ]);

        $q4 = $this->differ()->forQuarter($facts, $this->year(), $this->threeQuarters(), 3);

        $this->assertSame(-200.0, $q4['values']['operating_cash_flow']);
        $this->assertSame(DerivationKind::Derived, $q4['kind']);
    }

    public function test_all_seven_cashflow_fields_are_differenced(): void
    {
        $facts = ['facts' => ['us-gaap' => []]];

        foreach ([
            'NetCashProvidedByUsedInOperatingActivities',
            'NetCashProvidedByUsedInInvestingActivities',
            'NetCashProvidedByUsedInFinancingActivities',
            'PaymentsToAcquirePropertyPlantAndEquipment',
            'DepreciationDepletionAndAmortization',
            'ShareBasedCompensation',
            'CashAndCashEquivalentsPeriodIncreaseDecrease',
        ] as $tag) {
            $facts['facts']['us-gaap'][$tag] = ['units' => ['USD' => [
                ['start' => '2025-01-01', 'end' => '2025-03-31', 'val' => 100.0,
                    'form' => '10-Q', 'filed' => '2025-05-01', 'accn' => 'a'],
                ['start' => '2025-01-01', 'end' => '2025-06-30', 'val' => 300.0,
                    'form' => '10-Q', 'filed' => '2025-08-01', 'accn' => 'b'],
            ]]];
        }

        $q2 = $this->differ()->forQuarter($facts, $this->year(), $this->threeQuarters(), 1);

        foreach ((array) config('financial_statements.cashflow_fields') as $field) {
            $this->assertNotNull($q2['values'][$field], "{$field} 也必須差分，不是只有 OCF");
        }
    }

    public function test_derived_fourth_quarter_anchor_is_fiscal_year_end_not_a_computed_offset(): void
    {
        // 迴歸測試：刻意用非標準季長，讓「最後一季 end + 一季（91 天）」與 fyEnd
        // 相差超過 ±3 天容忍度（0915 + 91 天 = 1215，離 fyEnd 1231 還有 16 天）。
        // 若實作誤寫成「錨到推算出來的日期」而非「錨到 fyEnd」，這裡才會抓到——
        // 用標準三個月季長時兩者恰好只差 1 天，會被容忍度蓋過去，測不出差異。
        $year = new FiscalYearBoundary(2025, '2025-01-01', '2025-12-31', PeriodType::Annual);
        $quarters = [
            ['start' => '2025-01-01', 'end' => '2025-03-15'],
            ['start' => '2025-03-16', 'end' => '2025-06-15'],
            ['start' => '2025-06-16', 'end' => '2025-09-15'],
        ];
        $facts = $this->facts([
            ['2025-01-01', '2025-09-15', -400.0],
            ['2025-01-01', '2025-12-31', -600.0], // 只在真正的 fyEnd 才有列
        ]);

        $q4 = $this->differ()->forQuarter($facts, $year, $quarters, 3);

        $this->assertSame(-200.0, $q4['values']['operating_cash_flow']);
    }

    public function test_ytd_lookup_rejects_end_match_from_a_different_fiscal_year_start(): void
    {
        // 迴歸測試：ytd() 除了比對 end，還必須比對 start 是否落在同一財政年度的
        // fyStart 容忍窗內。同一個 tag 底下常年年都有 YTD 列，若只認 end，
        // 另一個財政年度、start 差了一整年但 end 恰好落在 ±3 天容忍窗內的列
        // 會被誤配進來——這裡刻意放一組 2024 財年（start=2024-01-01）的干擾列，
        // 其 end（2025-06-29）只比目標 Q2 end（2025-06-30）差 1 天。刻意不用
        // facts() helper：那裡 filed 綁死等於 end，干擾列的 end 較早、filed 也會
        // 較早，"filed 較晚者勝出" 的 tie-break 會讓正確列自動贏，測不出誤配。
        // 這裡改成手動給干擾列一個比正確列更晚的 filed 日期，確保「若 fyStart
        // 過濾被拿掉」時干擾列會贏過正確列被選中，讓誤配後的數字明顯錯到一眼看出。
        $tag = 'NetCashProvidedByUsedInOperatingActivities';
        $facts = ['facts' => ['us-gaap' => [$tag => ['units' => ['USD' => [
            ['start' => '2025-01-01', 'end' => '2025-03-31', 'val' => -100.0,
                'form' => '10-Q', 'filed' => '2025-05-01', 'accn' => 'a1'], // 正確：Q1 YTD（fyStart=2025-01-01）
            ['start' => '2025-01-01', 'end' => '2025-06-30', 'val' => -250.0,
                'form' => '10-Q', 'filed' => '2025-08-01', 'accn' => 'a2'], // 正確：Q2 YTD（fyStart=2025-01-01）
            ['start' => '2024-01-01', 'end' => '2025-06-29', 'val' => -999000.0,
                'form' => '10-Q', 'filed' => '2025-09-01', 'accn' => 'a3'], // 干擾：fyStart=2024-01-01，end 落在容忍窗內、filed 更晚
        ]]]]]];

        $q2 = $this->differ()->forQuarter($facts, $this->year(), $this->threeQuarters(), 1);

        $this->assertSame(-150.0, $q2['values']['operating_cash_flow']);
    }

    public function test_rgti_2026_first_half_matches_the_measured_shape(): void
    {
        // 實測值：2026-01-01~2026-03-31 = -16,216,000（Q1 單季）
        //         2026-01-01~2026-06-30 = -31,993,000（半年 YTD）
        // 因此 Q2 單季 = -31,993,000 − (-16,216,000) = -15,777,000
        $year = new FiscalYearBoundary(2026, '2026-01-01', '2026-12-31', PeriodType::Annual);
        $quarters = [
            ['start' => '2026-01-01', 'end' => '2026-03-31'],
            ['start' => '2026-04-01', 'end' => '2026-06-30'],
        ];

        $q2 = $this->differ()->forQuarter(SecFixture::load('rgti'), $year, $quarters, 1);

        $this->assertSame(-15777000.0, $q2['values']['operating_cash_flow']);
    }
}
