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

    public function test_amounts_keep_full_precision(): void
    {
        // 台積電年營收約 2.9 兆新台幣，double 會失精度。
        $instrument = Instrument::factory()->create();

        FinancialStatement::create([
            'instrument_id' => $instrument->id,
            'period_type' => 'annual',
            'fiscal_year' => 2024,
            'fiscal_quarter' => 0,
            'period_label' => 'FY2024',
            'period_start' => '2024-01-01',
            'period_end' => '2024-12-31',
            'fiscal_year_complete' => true,
            'currency' => 'TWD',
            'source' => 'finmind',
            'revenue' => '2894307699000.00',
        ]);

        // 不能直接 assertSame 原始字串：sqlite 的 decimal 欄位只有 numeric affinity
        // （SQLiteGrammar::typeDecimal 回傳純 'numeric'，不帶精度／小數位資訊），
        // 對「看起來是整數」的值會直接存成 INTEGER storage class，讀回來變成
        // int(2894307699000)，尾端的 .00 消失（實測：typeof() 回報 'integer'）。
        // 這是 sqlite 動態型別的顯示格式差異，不是精度真的遺失——數值本身仍精確為
        // 2894307699000。用 bcadd 正規化成 2 位小數比較，兩種 driver（sqlite 存
        // 整數、MySQL 存固定 scale 字串）都能通過，且真的截斷或四捨五入時仍會抓到。
        $stored = DB::table('financial_statements')->value('revenue');

        $this->assertSame('2894307699000.00', bcadd((string) $stored, '0', 2));
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
