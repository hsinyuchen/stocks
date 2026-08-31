<?php

namespace Tests\Feature\FinancialStatements;

use App\Enums\PeriodType;
use App\Models\FinancialStatement;
use App\Models\Instrument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinancialStatementSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_configured_field_has_a_column(): void
    {
        // 欄位清單的權威來源是 config，不是這個測試裡的字面值——擷取層產出的
        // values 鍵直接用它，兩邊對不上就會有欄位靜默寫不進去。
        $fields = array_merge(
            (array) config('financial_statements.income_fields'),
            array_keys((array) config('financial_statements.sec_eps_tags')),
            (array) config('financial_statements.instant_fields'),
            (array) config('financial_statements.cashflow_fields'),
        );

        $this->assertCount(33, $fields, '三張表合計 33 個科目');

        foreach ($fields as $field) {
            $this->assertTrue(
                Schema::hasColumn('financial_statements', $field),
                "financial_statements 缺少科目欄位 {$field}"
            );
        }
    }

    public function test_slot_is_unique_per_instrument(): void
    {
        $instrument = Instrument::factory()->create();

        $row = [
            'instrument_id' => $instrument->id,
            'period_type' => 'quarter',
            'fiscal_year' => 2026,
            'fiscal_quarter' => 2,
            'period_label' => '2026Q2',
            'period_start' => '2026-04-01',
            'period_end' => '2026-06-30',
            'fiscal_year_complete' => true,
            'currency' => 'USD',
            'source' => 'sec',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('financial_statements')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('financial_statements')->insert($row);
    }

    public function test_annual_rows_use_zero_not_null_for_fiscal_quarter(): void
    {
        // SQL 標準與 MySQL InnoDB 的唯一索引把每個 NULL 視為互異：fiscal_quarter
        // 若 nullable，年度列會完全不受唯一鍵約束、無限堆積重複。本專案在
        // MySQL 9.3.0 實測過 NULL 版本連插三次全部成功。
        $column = Schema::getColumns('financial_statements');
        $fiscalQuarter = collect($column)->firstWhere('name', 'fiscal_quarter');

        $this->assertNotNull($fiscalQuarter);
        $this->assertFalse($fiscalQuarter['nullable'], 'fiscal_quarter 絕不可為 nullable');
    }

    public function test_amount_and_eps_columns_declare_decimal_type(): void
    {
        // 這條測試守的是「欄位宣告型別」，不是「金額全精度」——後者本質上只在
        // MySQL 成立，測試環境是 sqlite，sqlite 沒有原生 fixed-point decimal
        // 儲存機制：SQLiteGrammar::typeDecimal() 產生裸的 'numeric'，其 numeric
        // affinity 底層一律退化成 IEEE754 double。實測對同一個大數值
        // （2894307699012.34）插入讀出，decimal(20,2) 欄位與純 double 欄位表現
        // 完全相同，插入讀出比數值在 sqlite 上量不出任何差異，因此原本用
        // assertSame + bcadd 比對數值的寫法無法在 sqlite 上驗證「不是 double」
        // 這個 schema 決策（把 migration 的 decimal(20,2) 改成 double 也會通過）。
        //
        // 改成比對欄位宣告型別字串：decimal 家族在 sqlite 下的 type_name 固定是
        // 'numeric'，double/float 家族是 'double'/'real'，兩者字串不同，足以擋下
        // 把 decimal 誤改成 double 的變異。
        //
        // 涵蓋不到的部分：sqlite 的欄位型別字串不帶精度／小數位資訊——實測用
        // sqlite_master 的 raw DDL 確認過，decimal(20,2) 與 decimal(12,4) 產生的
        // 都是裸的 "numeric"，完全相同。所以這裡驗證不了 decimal 內部精度／
        // 小數位的變化（例如把 eps_basic 的 (12,4) 誤改成 (20,2)）——那在 sqlite
        // 上結構性地不可觀測，真正的精度驗證只能留給 MySQL（見
        // .superpowers/sdd/task-1-report.md 的手動驗證紀錄）。
        $columns = collect(Schema::getColumns('financial_statements'))->keyBy('name');

        $this->assertSame(
            'numeric',
            $columns['revenue']['type_name'],
            'revenue 應宣告為 decimal 家族（sqlite 下 type_name 為 numeric），不是 double/float'
        );
        $this->assertSame(
            'numeric',
            $columns['eps_basic']['type_name'],
            'eps_basic 應宣告為 decimal 家族（sqlite 下 type_name 為 numeric），不是 double/float'
        );
    }

    public function test_slot_invariant_holds_for_every_period_type(): void
    {
        // CHECK 約束只在 MySQL 生效（sqlite 不支援 ALTER TABLE ADD CONSTRAINT），
        // 而測試環境是 sqlite。兩邊都要有，否則這條不變式在測試裡完全沒人守。
        $cases = [
            [PeriodType::Quarter, 1, true], [PeriodType::Quarter, 4, true],
            [PeriodType::Quarter, 0, false], [PeriodType::Quarter, 5, false],
            [PeriodType::Annual, 0, true], [PeriodType::Annual, 1, false],
            [PeriodType::Stub, 1, true], [PeriodType::Stub, 0, false],
        ];

        foreach ($cases as [$type, $quarter, $valid]) {
            $this->assertSame(
                $valid,
                FinancialStatement::slotIsValid($type, $quarter),
                "{$type->value} 的 fiscal_quarter = {$quarter}"
            );
        }
    }
}
