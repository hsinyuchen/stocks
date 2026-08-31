<?php

namespace Tests\Unit\FinancialStatements;

use App\Data\FinancialPeriod;
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
        $positive = $this->normalize(cashflow: [$this->row('2025-03-31', 'PropertyAndPlantAndEquipment', 500)]);
        $negative = $this->normalize(cashflow: [$this->row('2025-06-30', 'PropertyAndPlantAndEquipment', -500)]);

        $this->assertSame(-500.0, $positive->values['capex']);
        $this->assertSame(-500.0, $negative->values['capex']);
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
}
