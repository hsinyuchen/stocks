<?php

namespace Tests\Feature\FinancialStatements;

use App\Jobs\FetchFinancialStatements;
use App\Models\FinancialStatement;
use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use App\Services\FinancialStatements\FinancialStatementDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FinancialStatementDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private Instrument $instrument;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->instrument = Instrument::factory()->create();
    }

    private function dispatcher(): FinancialStatementDispatcher
    {
        return app(FinancialStatementDispatcher::class);
    }

    private function existing(string $status, ?string $retryAfter = null, int $generation = 1): void
    {
        FinancialStatementFetch::create([
            'instrument_id' => $this->instrument->id,
            'generation' => $generation, 'status' => $status,
            'attempts' => 1, 'queued_at' => now()->subHour(),
            'retry_after' => $retryAfter,
        ]);
    }

    public function test_first_request_creates_the_row_and_dispatches_generation_one(): void
    {
        $this->assertTrue($this->dispatcher()->dispatchFor($this->instrument));

        $fetch = FinancialStatementFetch::first();
        $this->assertSame(1, $fetch->generation);
        $this->assertSame('queued', $fetch->status);

        Queue::assertPushed(FetchFinancialStatements::class, fn ($job) => $job->generation === 1
            && $job->instrumentId === $this->instrument->id);
    }

    public function test_terminal_state_past_its_retry_after_bumps_the_generation(): void
    {
        $this->existing('failed', now()->subMinute()->toDateTimeString(), generation: 4);

        $this->assertTrue($this->dispatcher()->dispatchFor($this->instrument));

        $this->assertSame(5, FinancialStatementFetch::first()->generation);
        Queue::assertPushed(FetchFinancialStatements::class, fn ($job) => $job->generation === 5);
    }

    public function test_running_is_never_overwritten(): void
    {
        // MySQL 的 ON DUPLICATE KEY UPDATE 不支援 WHERE，用它會把 running
        // 無條件覆寫成 queued，直接打穿 CAS。
        $this->existing('running');

        $this->assertFalse($this->dispatcher()->dispatchFor($this->instrument));

        $this->assertSame('running', FinancialStatementFetch::first()->status);
        $this->assertSame(1, FinancialStatementFetch::first()->generation);
        Queue::assertNothingPushed();
    }

    public function test_queued_is_never_re_dispatched(): void
    {
        $this->existing('queued');

        $this->assertFalse($this->dispatcher()->dispatchFor($this->instrument));

        Queue::assertNothingPushed();
    }

    public function test_retry_after_in_the_future_blocks_the_dispatch(): void
    {
        // unsupported 的 7 天若只是文字，每次瀏覽都會立刻重派、白打上游。
        $this->existing('unsupported', now()->addDays(6)->toDateTimeString());

        $this->assertFalse($this->dispatcher()->dispatchFor($this->instrument));

        Queue::assertNothingPushed();
    }

    public function test_null_retry_after_does_not_block(): void
    {
        // 這也是 I-2「succeeded 但完全沒有表列」的情境：抓成功但上游零期間
        // （剛上市、財報尚未揭露），isFresh() 不能把「沒有資料」誤判成「新鮮」，
        // 否則這種標的會永久卡死、再也沒有機會重試。
        $this->existing('succeeded', null);

        $this->assertTrue($this->dispatcher()->dispatchFor($this->instrument));
    }

    /**
     * 建一列真正落地的財報資料，供新鮮度測試使用。
     */
    private function period(?string $fetchedAt = null): FinancialStatement
    {
        $at = $fetchedAt ?? now()->toDateTimeString();

        return FinancialStatement::create([
            'instrument_id' => $this->instrument->id,
            'period_type' => 'quarter',
            'fiscal_year' => 2026, 'fiscal_quarter' => 1, 'period_label' => '2026Q1',
            'period_start' => '2026-01-01', 'period_end' => '2026-03-31',
            'fiscal_year_complete' => false, 'currency' => 'USD', 'source' => 'sec',
            'revenue' => 100,
            'income_fetched_at' => $at, 'balance_fetched_at' => $at, 'cashflow_fetched_at' => $at,
        ]);
    }

    /**
     * I-2 的核心：spec 明文「succeeded 不用 retry_after，由表列的 fetched_at
     * 與 30 天新鮮度決定」。retry_after 為 null 加上 terminal，claim() 原本的
     * 判準恆為真，任何 dispatchFor() 呼叫（包括單純的頁面渲染）都會對新鮮
     * 資料重派一次工——這裡驗證新鮮時確實被擋下。
     */
    public function test_succeeded_and_fresh_does_not_redispatch(): void
    {
        $this->period();
        $this->existing('succeeded', null);

        $this->assertFalse($this->dispatcher()->dispatchFor($this->instrument));

        Queue::assertNothingPushed();
        $this->assertSame(1, FinancialStatementFetch::first()->generation, '新鮮時不該遞增 generation');
    }

    /**
     * 新鮮度規則與 FinancialStatementsReader::isStale() 同一套（跨列取最新、
     * 跨欄取最舊）：任一張表（損益／資產負債／現金流）超過
     * freshness_days（測試環境預設 30 天）就算過期，過期時仍要能重派工。
     */
    public function test_succeeded_but_stale_redispatches(): void
    {
        $this->period(now()->subDays(31)->toDateTimeString());
        $this->existing('succeeded', null);

        $this->assertTrue($this->dispatcher()->dispatchFor($this->instrument));

        Queue::assertPushed(FetchFinancialStatements::class, fn ($job) => $job->generation === 2);
    }

    public function test_re_dispatch_clears_the_previous_error(): void
    {
        FinancialStatementFetch::create([
            'instrument_id' => $this->instrument->id,
            'generation' => 2, 'status' => 'failed', 'attempts' => 2,
            'queued_at' => now()->subHour(), 'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(), 'error_category' => 'timeout',
        ]);

        $this->dispatcher()->dispatchFor($this->instrument);

        $fetch = FinancialStatementFetch::first();
        $this->assertNull($fetch->error_category, '舊的失敗原因不該留著誤導畫面');
        $this->assertNull($fetch->started_at);
        $this->assertSame(0, $fetch->attempts);
    }

    public function test_dispatching_twice_in_a_row_is_idempotent(): void
    {
        // 這是單執行緒測試，驗的是「同一請求路徑連續呼叫兩次」的冪等性，
        // 不是多連線併發下 INSERT IGNORE／唯一鍵的行為：第一次呼叫後該列
        // 已是 queued，第二次呼叫落到第二步的條件 UPDATE，queued 不在允許
        // 集合裡 → 不派工。真正的併發安全由 DB 唯一鍵約束保證，要驗證那個
        // 需要多程序整合測試，不在本測試範圍內。
        $this->assertTrue($this->dispatcher()->dispatchFor($this->instrument));
        $this->assertFalse($this->dispatcher()->dispatchFor($this->instrument));

        Queue::assertPushed(FetchFinancialStatements::class, 1);
    }

    public function test_first_request_initializes_attempts_and_queued_at(): void
    {
        // brief 原始測試沒驗證這兩個欄位：INSERT 語句裡任何一個欄位打錯
        // （例如 attempts 寫死非 0、漏掉 queued_at）都不會讓既有測試變紅。
        $this->dispatcher()->dispatchFor($this->instrument);

        $fetch = FinancialStatementFetch::first();
        $this->assertSame(0, $fetch->attempts);
        $this->assertNotNull($fetch->queued_at);
    }

    public function test_dispatching_one_instrument_does_not_touch_another_instruments_row(): void
    {
        // 條件 UPDATE／SELECT 若漏掉 instrument_id 篩選，兩檔標的都在終態、
        // 都過了退避期時，可能認領到別人的列而不是自己的——這是資料歸屬層級
        // 的風險，不是單純的邏輯錯誤。刻意讓「別人」的列先建立，若 SELECT
        // 忘了篩選通常會先撈到它。
        $other = Instrument::factory()->create();
        FinancialStatementFetch::create([
            'instrument_id' => $other->id,
            'generation' => 9, 'status' => 'failed', 'attempts' => 1,
            'queued_at' => now()->subHour(), 'retry_after' => now()->subMinute(),
        ]);
        $this->existing('failed', now()->subMinute()->toDateTimeString(), generation: 4);

        $this->assertTrue($this->dispatcher()->dispatchFor($this->instrument));

        $mine = FinancialStatementFetch::where('instrument_id', $this->instrument->id)->first();
        $theirs = FinancialStatementFetch::where('instrument_id', $other->id)->first();

        $this->assertSame(5, $mine->generation);
        $this->assertSame('queued', $mine->status);
        $this->assertSame(9, $theirs->generation, '不該動到別的 instrument 的列');
        $this->assertSame('failed', $theirs->status);

        Queue::assertPushed(FetchFinancialStatements::class, fn ($job) => $job->instrumentId === $this->instrument->id
            && $job->generation === 5);
    }
}
