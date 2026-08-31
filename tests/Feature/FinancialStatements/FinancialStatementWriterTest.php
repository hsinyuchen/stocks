<?php

namespace Tests\Feature\FinancialStatements;

use App\Data\FinancialPeriod;
use App\Data\PeriodFactSet;
use App\Enums\DerivationKind;
use App\Enums\PeriodType;
use App\Models\FinancialStatement;
use App\Models\Instrument;
use App\Services\FinancialStatements\FinancialStatementWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialStatementWriterTest extends TestCase
{
    use RefreshDatabase;

    private Instrument $instrument;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instrument = Instrument::factory()->create();
    }

    private function quarter(int $year, int $q, array $values = ['revenue' => 100.0]): FinancialPeriod
    {
        return new FinancialPeriod(
            periodType: PeriodType::Quarter,
            fiscalYear: $year,
            fiscalQuarter: $q,
            periodLabel: $year.'Q'.$q,
            periodStart: sprintf('%d-%02d-01', $year, ($q - 1) * 3 + 1),
            periodEnd: sprintf('%d-%02d-28', $year, $q * 3),
            fiscalYearComplete: true,
            currency: 'USD',
            values: $values,
        );
    }

    private function annual(int $year, array $values = ['revenue' => 400.0]): FinancialPeriod
    {
        return new FinancialPeriod(
            periodType: PeriodType::Annual,
            fiscalYear: $year,
            fiscalQuarter: 0,
            periodLabel: 'FY'.$year,
            periodStart: $year.'-01-01',
            periodEnd: $year.'-12-31',
            fiscalYearComplete: true,
            currency: 'USD',
            values: $values,
        );
    }

    private function write(array $periods): void
    {
        app(FinancialStatementWriter::class)->write(
            $this->instrument,
            new PeriodFactSet($periods, 'us'),
            'sec'
        );
    }

    private function labels(): array
    {
        return FinancialStatement::where('instrument_id', $this->instrument->id)
            ->orderBy('fiscal_year')->orderBy('fiscal_quarter')
            ->pluck('period_label')->all();
    }

    public function test_writes_every_period_with_its_values(): void
    {
        $this->write([$this->quarter(2026, 1, ['revenue' => 1868000.0, 'net_income' => -500.0])]);

        $row = FinancialStatement::first();

        $this->assertSame('2026Q1', $row->period_label);
        // brief 原文用 assertSame('1868000.00', ...)：FinancialStatement 沒有
        // decimal cast（Task 1 的檔案，本 task 不可修改），money 欄位在 sqlite
        // 下走 NUMERIC affinity 讀回來是 int/float 不是定寬字串，這點
        // FinancialStatementSchemaTest::test_amount_and_eps_columns_declare_decimal_type
        // 已經記錄過同一個限制。這裡改用數值比對，驗證的是 Writer 有沒有把值
        // 寫對，不是 Eloquent 的顯示格式（不在本 task 範圍）。
        $this->assertEquals(1868000.0, $row->revenue);
        $this->assertEquals(-500.0, $row->net_income, '負值是合法的財報數字');
        $this->assertSame('sec', $row->source);
    }

    public function test_null_and_zero_are_stored_distinctly(): void
    {
        // 財報上「該科目為 0」與「該科目無資料」是兩件事。
        $this->write([$this->quarter(2026, 1, ['revenue' => 0.0, 'net_income' => null])]);

        $row = FinancialStatement::first();

        // 見上方 test_writes_every_period_with_its_values 的說明：改用數值比對。
        $this->assertEquals(0.0, $row->revenue);
        $this->assertNull($row->net_income);
    }

    public function test_rewriting_the_same_slot_updates_in_place(): void
    {
        $this->write([$this->quarter(2026, 1, ['revenue' => 100.0])]);
        $this->write([$this->quarter(2026, 1, ['revenue' => 200.0])]);

        $this->assertSame(1, FinancialStatement::count());
        $this->assertEquals(200.0, FinancialStatement::first()->revenue);
    }

    public function test_reconciliation_deletes_only_inside_the_produced_slot_range(): void
    {
        // 這是本 task 的核心。第一次寫入 2021Q1–2021Q4。
        $this->write([
            $this->quarter(2021, 1), $this->quarter(2021, 2),
            $this->quarter(2021, 3), $this->quarter(2021, 4),
        ]);

        // 第二次視窗只涵蓋 2021Q2 起（模擬 20 季視窗往前滾動）。
        // 2021Q1 的槽位序號 20211 小於本次 min 20212，不在權威範圍內，必須保留。
        $this->write([
            $this->quarter(2021, 2), $this->quarter(2021, 3), $this->quarter(2021, 4),
        ]);

        $this->assertSame(['2021Q1', '2021Q2', '2021Q3', '2021Q4'], $this->labels());
    }

    public function test_reconciliation_deletes_a_slot_that_disappeared_inside_the_range(): void
    {
        // 區間內、本次未產出 → 該刪。期間消失或 fiscal label 被更正時，
        // 少了這一步舊列會永遠殘留。
        $this->write([
            $this->quarter(2021, 1), $this->quarter(2021, 2),
            $this->quarter(2021, 3), $this->quarter(2021, 4),
        ]);

        $this->write([
            $this->quarter(2021, 1), $this->quarter(2021, 2), $this->quarter(2021, 4),
        ]);

        $this->assertSame(['2021Q1', '2021Q2', '2021Q4'], $this->labels());
    }

    public function test_annual_uses_a_set_not_a_range(): void
    {
        // 中間整年解析失敗時，用 min/max 區間會把那一年刪掉。
        $this->write([$this->annual(2022), $this->annual(2023), $this->annual(2024)]);

        $this->write([$this->annual(2022), $this->annual(2024)]);

        $this->assertSame(['FY2022', 'FY2023', 'FY2024'], $this->labels());
    }

    public function test_empty_set_deletes_nothing(): void
    {
        // 一次解析失敗不該清空使用者看得到的全部歷史。
        $this->write([$this->quarter(2026, 1)]);
        $this->write([]);

        $this->assertSame(['2026Q1'], $this->labels());
    }

    public function test_other_instruments_are_never_touched(): void
    {
        $other = Instrument::factory()->create();
        app(FinancialStatementWriter::class)->write(
            $other,
            new PeriodFactSet([$this->quarter(2026, 1)], 'us'),
            'sec'
        );

        $this->write([$this->quarter(2026, 2)]);

        $this->assertSame(1, FinancialStatement::where('instrument_id', $other->id)->count());
    }

    public function test_reconciliation_never_deletes_another_instruments_row_in_the_same_slot_range(): void
    {
        // brief 原文的 test_other_instruments_are_never_touched 沒有真的釘住這條規則：
        // 兩次寫入落在不同槽位（2026Q1 vs 2026Q2），reconcile 的刪除範圍
        // [min,max] whereNotIn(產出槽位) 對「只產出自己那個槽位」的情境恆為空集合，
        // 拿掉 reconcileQuarters 的 where('instrument_id', ...) 一樣全數通過，
        // 等於沒測到東西。這裡改用兩個 instrument 的槽位序號**互相重疊**，
        // 讓 instrument A 的 2021Q3 落在 instrument B 本次產出的槽位區間內、
        // 且不在 B 的產出集合裡——若刪除少了 instrument_id 篩選，會連坐刪掉 A 的列。
        $other = Instrument::factory()->create();
        app(FinancialStatementWriter::class)->write(
            $other,
            new PeriodFactSet([
                $this->quarter(2021, 1), $this->quarter(2021, 2),
                $this->quarter(2021, 3), $this->quarter(2021, 4),
            ], 'us'),
            'sec'
        );

        $this->write([
            $this->quarter(2021, 1), $this->quarter(2021, 2),
            $this->quarter(2021, 3), $this->quarter(2021, 4),
        ]);
        // 觸發 reconciliation：本 instrument 的 2021Q3 這次沒有產出，
        // 範圍 [20211,20214] 內、不在產出集合裡，理應被刪除——僅限本 instrument。
        $this->write([
            $this->quarter(2021, 1), $this->quarter(2021, 2), $this->quarter(2021, 4),
        ]);

        $this->assertSame(
            ['2021Q1', '2021Q2', '2021Q3', '2021Q4'],
            FinancialStatement::where('instrument_id', $other->id)
                ->orderBy('fiscal_quarter')->pluck('period_label')->all(),
            '另一個 instrument 落在同一槽位區間內的列不可被連坐刪除'
        );
        $this->assertSame(['2021Q1', '2021Q2', '2021Q4'], $this->labels());
    }

    public function test_provenance_and_fetched_at_are_recorded_per_statement(): void
    {
        $period = new FinancialPeriod(
            periodType: PeriodType::Quarter,
            fiscalYear: 2026, fiscalQuarter: 1, periodLabel: '2026Q1',
            periodStart: '2026-01-01', periodEnd: '2026-03-31',
            fiscalYearComplete: false, currency: 'USD',
            values: ['revenue' => 1.0],
            incomeDerivation: DerivationKind::Derived,
            cashflowDerivation: DerivationKind::Direct,
            incomeRestatementMixed: true,
            incomeSourceAccn: '0001193125-10-238044',
            balanceSourceAccn: '0001193125-10-238045',
            cashflowSourceAccn: null,
        );

        $this->write([$period]);
        $row = FinancialStatement::first();

        $this->assertSame(DerivationKind::Derived, $row->income_derivation);
        $this->assertSame(DerivationKind::Direct, $row->cashflow_derivation);
        $this->assertTrue($row->income_restatement_mixed);
        $this->assertFalse($row->cashflow_restatement_mixed);
        $this->assertSame('0001193125-10-238044', $row->income_source_accn);
        $this->assertNull($row->cashflow_source_accn);
        // 三個 fetched_at 都要蓋章：本次三張表都是同一次抓取的結果。
        $this->assertNotNull($row->income_fetched_at);
        $this->assertNotNull($row->balance_fetched_at);
        $this->assertNotNull($row->cashflow_fetched_at);
    }

    public function test_stub_rows_survive_when_their_year_is_absent_from_the_annual_set(): void
    {
        $stub = new FinancialPeriod(
            periodType: PeriodType::Stub,
            fiscalYear: 2021, fiscalQuarter: 1, periodLabel: '2021S1',
            periodStart: '2021-01-01', periodEnd: '2021-11-30',
            fiscalYearComplete: false, currency: 'USD',
            values: ['revenue' => 5.0],
        );

        $this->write([$stub, $this->annual(2021)]);
        // 下一次 2021 完全沒出現在 annual 集合裡 → 它的 stub 不動。
        $this->write([$this->annual(2022)]);

        $this->assertContains('2021S1', $this->labels());
    }
}
