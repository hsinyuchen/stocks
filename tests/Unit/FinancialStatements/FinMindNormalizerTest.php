<?php

namespace Tests\Unit\FinancialStatements;

use App\Data\FinancialPeriod;
use App\Enums\DerivationKind;
use App\Enums\PeriodType;
use App\Services\FinancialStatements\FinMindNormalizer;
use Tests\TestCase;

/**
 * 純函式層級的規則驗證，不經 HTTP。
 *
 * 與 FinMindFinancialStatementSourceTest 互補：Feature 測試釘住「線路接得對不對」，
 * 這裡釘住「正規化規則本身」——尤其是那些用既有 finmind_types 設定資料無法
 * 觸發、必須用合成資料才能證明的規則（見 test_per_suffix_is_dropped_even_if_it_would_otherwise_map）。
 */
class FinMindNormalizerTest extends TestCase
{
    private function row(string $date, string $type, float $value): array
    {
        return ['date' => $date, 'stock_id' => '2330', 'type' => $type, 'value' => $value];
    }

    private function normalize(array $income = [], array $balance = [], array $cashflow = [], int $quarters = 12, int $years = 5): FinancialPeriod
    {
        $set = app(FinMindNormalizer::class)->normalize($income, $balance, $cashflow, $quarters, $years);

        $this->assertNotEmpty($set->periods);

        return $set->periods[0];
    }

    public function test_per_suffix_is_dropped_even_if_it_would_otherwise_map(): void
    {
        // 目前 finmind_types 的對照值都不以 _per 結尾，所以「拿掉 _per 濾除」在現有
        // 設定資料下對 array_search 的比對結果沒有影響（既有列本來就配不到）——
        // 這條規則若只靠現有設定資料驗證，變異測試不會變紅，等於沒被真正釘住。
        //
        // 用合成設定重現「假如某天 finmind_types 誤把一個 _per 結尾的型別名對照
        // 到欄位」的情境：沒有濾除的話這筆百分比列會被當金額寫進去；有濾除則
        // 無論設定檔內容為何都必須被丟棄。
        config(['financial_statements.finmind_types.balance.inventories' => 'Inventories_per']);

        // 另外混一筆正常列，確保這個期間本來就會產出（純測「inventories 有沒有
        // 被誤填」，不是測「有沒有任何期間」）。
        $period = $this->normalize(
            income: [$this->row('2025-03-31', 'Revenue', 1000)],
            balance: [$this->row('2025-03-31', 'Inventories_per', 12.5)],
        );

        $this->assertNull($period->values['inventories'], '_per 結尾的型別一律視為佔比列，不論設定檔怎麼對照');
    }

    public function test_all_configured_fields_default_to_null_not_undefined_or_zero(): void
    {
        // 台股制度性不揭露的科目（研發費用、SG&A、股權薪酬、現金淨變動）永遠拿不到
        // FinMind 原始列，必須明確是 null，而不是「陣列裡根本沒有這個鍵」
        // （那樣任何直接存取 $values['xxx'] 的消費端都會觸發 undefined array key）。
        $period = $this->normalize(income: [$this->row('2025-03-31', 'Revenue', 1000)]);

        foreach ([
            'research_development', 'selling_general_admin', 'share_based_compensation',
            'net_change_in_cash', 'eps_diluted', 'long_term_debt',
        ] as $field) {
            $this->assertArrayHasKey($field, $period->values, "{$field} 必須是明確的鍵");
            $this->assertNull($period->values[$field], "{$field} 沒有原始列時必須是 null");
        }
    }

    public function test_a_reported_zero_is_not_normalized_away(): void
    {
        $period = $this->normalize(income: [
            $this->row('2025-03-31', 'Revenue', 1000),
            $this->row('2025-03-31', 'TAX', 0),
        ]);

        $this->assertSame(0.0, $period->values['income_tax']);
    }

    public function test_capex_outflow_is_normalized_to_negative(): void
    {
        // FinMind 的 PropertyAndPlantAndEquipment 原值是負的（現金流出）；
        // SEC 對應科目卻是正值。統一存負值，兩個來源在同一欄位才是同一個意思。
        //
        // 註：現金流欄位是 YTD 累計（見 C1 差分修復），Q2 沒有前一季 YTD 就無法
        // 差分出單季值。這裡改用兩筆 YTD（Q1=-200、Q2=-700）讓 Q2 差分出
        // -500（-700 − (-200)），與修復前「Q2 單獨一筆直接當單季值」的舊斷言
        // 維持同一個期望值，只是輸入資料改成差分修復後仍然合法的形狀。
        $positive = $this->normalize(cashflow: [$this->row('2025-03-31', 'PropertyAndPlantAndEquipment', 500)]);
        $negativeSet = app(FinMindNormalizer::class)->normalize([], [], [
            $this->row('2025-03-31', 'PropertyAndPlantAndEquipment', -200),
            $this->row('2025-06-30', 'PropertyAndPlantAndEquipment', -700),
        ], 12, 5);

        $this->assertSame(-500.0, $positive->values['capex']);
        $this->assertSame(-500.0, $negativeSet->periods[1]->values['capex']);
    }

    public function test_unmapped_type_is_ignored(): void
    {
        $period = $this->normalize(income: [
            $this->row('2025-03-31', 'Revenue', 1000),
            $this->row('2025-03-31', 'SomeUnknownFinMindType', 999),
        ]);

        $this->assertSame(1000.0, $period->values['revenue']);
        $this->assertNotContains(999.0, $period->values, true);
    }

    public function test_quarters_are_truncated_to_the_requested_depth(): void
    {
        $rows = [
            $this->row('2024-03-31', 'Revenue', 1),
            $this->row('2024-06-30', 'Revenue', 2),
            $this->row('2024-09-30', 'Revenue', 3),
        ];

        $set = app(FinMindNormalizer::class)->normalize($rows, [], [], 2, 5);

        $this->assertCount(2, $set->periods);
        $this->assertSame(2, $set->periods[0]->fiscalQuarter);
        $this->assertSame(3, $set->periods[1]->fiscalQuarter);
    }

    public function test_period_type_and_label_come_from_the_quarter_end_date(): void
    {
        $period = $this->normalize(income: [$this->row('2025-09-30', 'Revenue', 1)]);

        $this->assertSame(PeriodType::Quarter, $period->periodType);
        $this->assertSame(2025, $period->fiscalYear);
        $this->assertSame(3, $period->fiscalQuarter);
        $this->assertSame('2025Q3', $period->periodLabel);
        $this->assertSame('2025-07-01', $period->periodStart);
        $this->assertSame('2025-09-30', $period->periodEnd);
        $this->assertSame('TWD', $period->currency);
        $this->assertTrue($period->fiscalYearComplete);
    }

    // --- C1：台股現金流是 YTD 累計，須年度內差分才是單季值 ---

    public function test_cashflow_ytd_is_differenced_into_single_quarter_values(): void
    {
        // 台股 IFRS 季報現金流量表只揭露「當年度累計至本期末」的數字（損益表
        // 則是單季值，兩者揭露慣例不同）。合成四季 YTD，驗證差分後的單季值：
        // Q1 直接採用（YTD 本身就是單季），Q2~Q4 = 本期 YTD − 前一季 YTD。
        $set = app(FinMindNormalizer::class)->normalize([], [], [
            $this->row('2024-03-31', 'CashFlowsFromOperatingActivities', 100),
            $this->row('2024-06-30', 'CashFlowsFromOperatingActivities', 250),
            $this->row('2024-09-30', 'CashFlowsFromOperatingActivities', 420),
            $this->row('2024-12-31', 'CashFlowsFromOperatingActivities', 630),
        ], 12, 5);

        $byQuarter = [];
        foreach ($set->periods as $p) {
            $byQuarter[$p->fiscalQuarter] = $p->values['operating_cash_flow'];
        }

        $this->assertSame(100.0, $byQuarter[1]);
        $this->assertSame(150.0, $byQuarter[2]);
        $this->assertSame(170.0, $byQuarter[3]);
        $this->assertSame(210.0, $byQuarter[4]);
    }

    public function test_missing_prior_quarter_leaves_the_cashflow_field_null(): void
    {
        // 只有 Q3 一筆 YTD、沒有 Q1／Q2：不可拿 YTD 直接當單季值（真值誤差可達
        // 數倍，見 2330 2024Q4 實測：直採 1,826.2bn vs 真值約 620.2bn），
        // 也不可跨期硬湊，必須留 null。
        $set = app(FinMindNormalizer::class)->normalize([], [], [
            $this->row('2024-09-30', 'CashFlowsFromOperatingActivities', 420),
        ], 12, 5);

        $this->assertNull($set->periods[0]->values['operating_cash_flow']);
    }

    public function test_cashflow_diff_does_not_cross_fiscal_years(): void
    {
        // 若把「前一季」誤實作成「排序後最後一筆非本季的資料」（不分年度），
        // 2024Q2 會被拿去減 2023Q4 的 YTD——兩個不同財政年度的累計基準相減
        // 沒有意義。這裡刻意讓 2024 年缺 Q1、只給 Q2，讓「跨年度找前一季」
        // 與「只認同一年度的前一季」在這個情境下算出不同答案：前者會拿
        // 2023Q4 當前一季硬湊出一個數字，正確的後者因同年度沒有 Q1 而留 null。
        $set = app(FinMindNormalizer::class)->normalize([], [], [
            $this->row('2023-03-31', 'CashFlowsFromOperatingActivities', 100),
            $this->row('2023-06-30', 'CashFlowsFromOperatingActivities', 250),
            $this->row('2023-09-30', 'CashFlowsFromOperatingActivities', 420),
            $this->row('2023-12-31', 'CashFlowsFromOperatingActivities', 630),
            $this->row('2024-06-30', 'CashFlowsFromOperatingActivities', 300), // 2024 缺 Q1，只有 Q2
        ], 12, 5);

        $byLabel = [];
        foreach ($set->periods as $p) {
            $byLabel[$p->periodLabel] = $p->values['operating_cash_flow'];
        }

        $this->assertSame(210.0, $byLabel['2023Q4'], '2023 全年鏈完整：630-420=210');
        $this->assertNull($byLabel['2024Q2'], '2024 缺 Q1，不可拿 2023Q4 的 YTD 當前一季硬湊差分');
    }

    public function test_income_statement_is_not_differenced(): void
    {
        // 損益表本來就是單季值（與現金流的 YTD 揭露慣例相反）。差分它會把
        // 原本正確的單季數字改錯，是這次修復本身可能引入的新 bug。
        $set = app(FinMindNormalizer::class)->normalize([
            $this->row('2024-03-31', 'Revenue', 100),
            $this->row('2024-06-30', 'Revenue', 250),
        ], [], [], 12, 5);

        $byQuarter = [];
        foreach ($set->periods as $p) {
            $byQuarter[$p->fiscalQuarter] = $p->values['revenue'];
        }

        $this->assertSame(100.0, $byQuarter[1]);
        $this->assertSame(250.0, $byQuarter[2], '損益表是單季值，不可被誤當 YTD 差分成 150');
    }

    public function test_cashflow_derivation_kind_reflects_diffing(): void
    {
        $set = app(FinMindNormalizer::class)->normalize([], [], [
            $this->row('2024-03-31', 'CashFlowsFromOperatingActivities', 100),
            $this->row('2024-06-30', 'CashFlowsFromOperatingActivities', 250),
        ], 12, 5);

        $byQuarter = [];
        foreach ($set->periods as $p) {
            $byQuarter[$p->fiscalQuarter] = $p;
        }

        $this->assertSame(DerivationKind::Direct, $byQuarter[1]->cashflowDerivation, 'Q1 的 YTD 本身就是單季值，屬直接採用');
        $this->assertSame(DerivationKind::Derived, $byQuarter[2]->cashflowDerivation);
    }

    public function test_capex_sign_normalization_applies_after_differencing_not_before(): void
    {
        // 順序陷阱：若對每筆原始 YTD 先套用 signed()、再拿「已簽名」的值相減，
        // 符號會被反轉錯誤方向。必須先用原始 YTD 差分，簽名只套用在差分後的
        // 單季值。這裡刻意用「Q2 YTD 小於 Q1 YTD」的合成數字（不代表真實會計
        // 情境，純粹為了讓兩種順序算出不同符號）讓兩種實作方式產生不同答案：
        // 先簽名再差分 → signed(300)=-300、signed(100)=-100，diff=-100-(-300)=200；
        // 先差分再簽名（正確）→ diff=100-300=-200，signed(-200)=-200（已為負不翻轉）。
        $set = app(FinMindNormalizer::class)->normalize([], [], [
            $this->row('2025-03-31', 'PropertyAndPlantAndEquipment', 300),
            $this->row('2025-06-30', 'PropertyAndPlantAndEquipment', 100),
        ], 12, 5);

        $this->assertSame(-200.0, $set->periods[1]->values['capex']);
    }
}
