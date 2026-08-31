<?php

namespace Tests\Feature\FinancialStatements;

use App\Enums\PeriodType;
use App\Models\FinancialStatement;
use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use App\Services\FinancialStatements\FinancialStatementsReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialStatementsReaderTest extends TestCase
{
    use RefreshDatabase;

    private Instrument $instrument;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instrument = Instrument::factory()->create();
    }

    private function row(int $year, int $q, ?string $fetchedAt = null): FinancialStatement
    {
        $at = $fetchedAt ?? now()->toDateTimeString();

        return FinancialStatement::create([
            'instrument_id' => $this->instrument->id,
            'period_type' => 'quarter',
            'fiscal_year' => $year,
            'fiscal_quarter' => $q,
            'period_label' => $year.'Q'.$q,
            'period_start' => sprintf('%d-%02d-01', $year, ($q - 1) * 3 + 1),
            'period_end' => sprintf('%d-%02d-28', $year, $q * 3),
            'fiscal_year_complete' => true,
            'currency' => 'USD',
            'source' => 'sec',
            'revenue' => 100,
            'income_fetched_at' => $at,
            'balance_fetched_at' => $at,
            'cashflow_fetched_at' => $at,
        ]);
    }

    private function state(string $status): void
    {
        FinancialStatementFetch::create([
            'instrument_id' => $this->instrument->id,
            'generation' => 1, 'status' => $status, 'attempts' => 1, 'queued_at' => now(),
        ]);
    }

    private function read(int $limit = 20): array
    {
        return app(FinancialStatementsReader::class)
            ->for($this->instrument, PeriodType::Quarter, $limit);
    }

    public function test_absent_when_there_is_nothing_at_all(): void
    {
        $this->assertSame('absent', $this->read()['state']);
    }

    public function test_fetching_when_in_flight_and_no_rows_yet(): void
    {
        $this->state('running');

        $this->assertSame('fetching', $this->read()['state']);
    }

    public function test_refreshing_when_in_flight_but_rows_already_exist(): void
    {
        // 有舊資料時畫面照常顯示並標「更新中」，不該退回骨架。
        $this->row(2026, 1);
        $this->state('queued');

        $this->assertSame('refreshing', $this->read()['state']);
    }

    public function test_ready_with_rows_newest_first(): void
    {
        $this->row(2025, 4);
        $this->row(2026, 1);
        $this->state('succeeded');

        $result = $this->read();

        $this->assertSame('ready', $result['state']);
        $this->assertSame(['2026Q1', '2025Q4'], array_map(
            static fn ($r) => $r->period_label, $result['periods']
        ));
    }

    public function test_limit_keeps_the_newest_not_the_oldest(): void
    {
        $this->row(2024, 1);
        $this->row(2025, 1);
        $this->row(2026, 1);
        $this->state('succeeded');

        $labels = array_map(static fn ($r) => $r->period_label, $this->read(2)['periods']);

        $this->assertSame(['2026Q1', '2025Q1'], $labels);
    }

    public function test_stale_uses_the_oldest_of_the_three_fetched_at(): void
    {
        // 只要有一張表過期，整列就算過期。
        $row = $this->row(2026, 1);
        $row->update(['cashflow_fetched_at' => now()->subDays(31)]);
        $this->state('succeeded');

        $this->assertTrue($this->read()['isStale']);
    }

    public function test_fresh_rows_are_not_stale(): void
    {
        $this->row(2026, 1);
        $this->state('succeeded');

        $this->assertFalse($this->read()['isStale']);
    }

    public function test_failed_state_carries_the_error_category(): void
    {
        FinancialStatementFetch::create([
            'instrument_id' => $this->instrument->id,
            'generation' => 1, 'status' => 'failed', 'attempts' => 2,
            'queued_at' => now(), 'error_category' => 'timeout',
        ]);

        $result = $this->read();

        $this->assertSame('failed', $result['state']);
        $this->assertSame('timeout', $result['errorCategory']);
    }

    public function test_unsupported_is_its_own_state(): void
    {
        FinancialStatementFetch::create([
            'instrument_id' => $this->instrument->id,
            'generation' => 1, 'status' => 'unsupported', 'attempts' => 1,
            'queued_at' => now(), 'error_category' => 'no_cik',
        ]);

        $this->assertSame('unsupported', $this->read()['state']);
    }

    public function test_reader_never_writes(): void
    {
        // 只讀入口不得順手派工——那會讓每一次頁面渲染都可能觸發外部請求。
        $this->read();

        $this->assertSame(0, FinancialStatementFetch::count());
    }

    public function test_other_instruments_rows_are_not_returned(): void
    {
        $other = Instrument::factory()->create();
        FinancialStatement::create([
            'instrument_id' => $other->id, 'period_type' => 'quarter',
            'fiscal_year' => 2026, 'fiscal_quarter' => 1, 'period_label' => '2026Q1',
            'period_start' => '2026-01-01', 'period_end' => '2026-03-31',
            'fiscal_year_complete' => true, 'currency' => 'USD', 'source' => 'sec',
        ]);

        $this->assertSame([], $this->read()['periods']);
    }

    // ------------------------------------------------------------------
    // 以下為 brief 的 Step 1 測試清單以外、審查時補上的迴歸測試。
    // 原因寫在各測試前的註解——都是「規則存在但沒被任何既有測試釘住」的缺口。
    // ------------------------------------------------------------------

    public function test_failed_status_with_existing_rows_falls_back_to_ready(): void
    {
        // 使用者手上有可看的資料時，一次抓取失敗不該把畫面整個換成錯誤頁。
        // 這條規則只有「沒有列時回 failed」被 test_failed_state_carries_the_error_category
        // 釘住，「有列時回 ready」完全沒有測試覆蓋——把 state() 的 failed 分支改成
        // 一律回 failed（不看有沒有列）不會讓任何既有測試變紅。
        $this->row(2026, 1);

        FinancialStatementFetch::create([
            'instrument_id' => $this->instrument->id,
            'generation' => 1, 'status' => 'failed', 'attempts' => 2,
            'queued_at' => now(), 'error_category' => 'timeout',
        ]);

        $result = $this->read();

        $this->assertSame('ready', $result['state']);
        // errorCategory 仍要傳出去，讓 UI 標一行「最近一次更新失敗」。
        $this->assertSame('timeout', $result['errorCategory']);
    }

    public function test_period_type_filters_out_other_period_types(): void
    {
        // 既有測試全部只用 quarter 期間，for() 的 where('period_type', ...)
        // 拿掉也不會讓任何測試變紅——annual 列會混進 quarter 查詢結果。
        $this->row(2026, 1);
        FinancialStatement::create([
            'instrument_id' => $this->instrument->id,
            'period_type' => 'annual',
            'fiscal_year' => 2025, 'fiscal_quarter' => 0, 'period_label' => 'FY2025',
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
            'fiscal_year_complete' => true, 'currency' => 'USD', 'source' => 'sec',
            'income_fetched_at' => now(), 'balance_fetched_at' => now(), 'cashflow_fetched_at' => now(),
        ]);

        $labels = array_map(static fn ($r) => $r->period_label, $this->read()['periods']);

        $this->assertSame(['2026Q1'], $labels);
    }

    public function test_stale_is_detected_from_any_single_column_not_just_cashflow(): void
    {
        // 既有的 stale 測試只動 cashflow_fetched_at。若實作偷懶只檢查
        // income_fetched_at 與 cashflow_fetched_at、漏了 balance_fetched_at，
        // 這條規則不會被任何既有測試抓到。
        $row = $this->row(2026, 1);
        $row->update(['balance_fetched_at' => now()->subDays(31)]);
        $this->state('succeeded');

        $this->assertTrue($this->read()['isStale']);
    }

    public function test_null_fetched_at_counts_as_stale(): void
    {
        // null 代表「這張表從沒抓成功過」，比「30 天前抓的」更不新鮮，不能被當成
        // 「沒有值所以不算過期」而放過。
        $row = $this->row(2026, 1);
        $row->update(['income_fetched_at' => null]);
        $this->state('succeeded');

        $this->assertTrue($this->read()['isStale']);
    }
}
