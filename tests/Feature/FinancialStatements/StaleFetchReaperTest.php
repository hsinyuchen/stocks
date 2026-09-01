<?php

namespace Tests\Feature\FinancialStatements;

use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use App\Services\FinancialStatements\FinancialStatementDispatcher;
use App\Services\FinancialStatements\StaleFetchReaper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StaleFetchReaperTest extends TestCase
{
    use RefreshDatabase;

    private function fetch(string $status, ?string $startedAt, ?string $queuedAt = null, int $generation = 3): FinancialStatementFetch
    {
        return FinancialStatementFetch::create([
            'instrument_id' => Instrument::factory()->create()->id,
            'generation' => $generation, 'status' => $status, 'attempts' => 1,
            'queued_at' => $queuedAt ?? now()->toDateTimeString(),
            'started_at' => $startedAt,
        ]);
    }

    private function reap(): int
    {
        return app(StaleFetchReaper::class)->reap();
    }

    public function test_running_past_the_threshold_is_reaped(): void
    {
        $fetch = $this->fetch('running', now()->subSeconds(300)->toDateTimeString());

        $this->assertSame(1, $this->reap());

        $fresh = $fetch->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('timeout', $fresh->error_category);
    }

    public function test_reaping_bumps_the_generation(): void
    {
        // 只改 status 的話，被判死的 worker 若其實還活著，仍能用原 generation
        // 寫入 succeeded 把判定蓋掉。
        $fetch = $this->fetch('running', now()->subSeconds(300)->toDateTimeString(), generation: 3);

        $this->reap();

        $this->assertSame(4, $fetch->fresh()->generation);
    }

    public function test_stuck_queued_is_also_reaped(): void
    {
        // CAS commit 之後、dispatch 之前崩潰，或 dispatch 本身失敗時，狀態會停在
        // queued 而沒有任何 job；後續派工又因 queued 不在允許集合被擋，永久死鎖。
        $fetch = $this->fetch('queued', null, now()->subSeconds(300)->toDateTimeString());

        $this->assertSame(1, $this->reap());
        $this->assertSame('failed', $fetch->fresh()->status);
    }

    public function test_running_inside_the_threshold_is_left_alone(): void
    {
        // 門檻要涵蓋 retry 間隙（2 × (60 + 30) + 60 = 240 秒），否則會在 backoff
        // 期間誤判死亡。
        $fetch = $this->fetch('running', now()->subSeconds(200)->toDateTimeString());

        $this->assertSame(0, $this->reap());
        $this->assertSame('running', $fetch->fresh()->status);
    }

    public function test_terminal_states_are_never_reaped(): void
    {
        $fetch = $this->fetch('succeeded', now()->subDays(30)->toDateTimeString());

        $this->assertSame(0, $this->reap());
        $this->assertSame('succeeded', $fetch->fresh()->status);
    }

    public function test_reaped_row_gets_a_retry_after_so_it_can_be_re_dispatched(): void
    {
        $fetch = $this->fetch('running', now()->subSeconds(300)->toDateTimeString());

        $this->reap();

        // 判死之後要能重派，但不能立刻重派（否則死一次就無限重打上游）。
        $this->assertNotNull($fetch->fresh()->retry_after);
    }

    // ---- 以下為自加變異覆蓋，brief 的四項官方變異之外自己推的缺口 ----

    /**
     * 'unsupported' 是永久性判定（指數／ETF 不會變），不能被誤判成暫時卡住而重派。
     *
     * 官方測試只驗了 'succeeded' 這一個終態；whereIn(['running','queued','unsupported'])
     * 這種把 'unsupported' 誤放進收割集合的變異，不會讓既有任何測試變紅。
     */
    public function test_unsupported_is_never_reaped(): void
    {
        $fetch = $this->fetch('unsupported', now()->subDays(30)->toDateTimeString());

        $this->assertSame(0, $this->reap());
        $this->assertSame('unsupported', $fetch->fresh()->status);
    }

    /**
     * 卡住的 queued 也要標成同一個 error_category（'timeout'），UI 才有一致的
     * 失敗原因可顯示。brief 給的 test_stuck_queued_is_also_reaped 只驗 status，
     * 若實作把 queued 和 running 兩條路徑分岔、只有 running 那支寫 error_category，
     * 不會被任何既有測試抓到。
     */
    public function test_reaped_queued_row_is_marked_timeout(): void
    {
        $fetch = $this->fetch('queued', null, now()->subSeconds(300)->toDateTimeString());

        $this->reap();

        $this->assertSame('timeout', $fetch->fresh()->error_category);
    }

    /**
     * 邊界要驗證比較運算子本身是 `<` 而不是 `<=`：門檻算式（240 秒）本身就是
     * 「剛好卡在退避間隙盡頭」的臨界值，`<=` 會多殺掉一批其實還在合法 backoff
     * 窗口內、下一刻就會完成的 job。用 Carbon::setTestNow() 凍結時間讓比較
     * 確定發生在同一瞬間，避免測試機器排程延遲造成的假陽性/假陰性。
     */
    public function test_row_exactly_at_the_threshold_boundary_is_not_reaped(): void
    {
        $staleSeconds = (int) config('financial_statements.job.stale_after_seconds');
        $now = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($now);

        $fetch = $this->fetch('running', $now->copy()->subSeconds($staleSeconds)->toDateTimeString());

        $this->assertSame(0, $this->reap());
        $this->assertSame('running', $fetch->fresh()->status);

        Carbon::setTestNow();
    }

    /**
     * 收割之後，退避到期時 dispatcher 要能正常重新派工——這是 Task 7／8 之間的
     * 交界：reaper 只改 status／generation／retry_after，不動 started_at 與
     * attempts，這兩欄的重置是 dispatcher claim() 的職責。如果 reaper 或
     * dispatcher 任一邊改了假設（例如 reaper 誤把 started_at 清空、或
     * dispatcher 不再重置 attempts），死鎖會用另一種形式回來：要嘛卡在
     * terminal 狀態被永遠當成「還在 backoff」，要嘛新 generation 帶著舊
     * attempts 計數。
     */
    public function test_reaped_row_can_be_redispatched_once_retry_after_elapses(): void
    {
        // 只驗 dispatcher 的 claim()／commit 邊界，不讓 job 真的跑——測試環境
        // QUEUE_CONNECTION=sync，不 fake 佇列的話 dispatch() 會同步跑完整條
        // FetchFinancialStatements 管線，job 本身的終態會蓋掉這裡要驗的中間態。
        Queue::fake();

        $fetch = $this->fetch('running', now()->subSeconds(300)->toDateTimeString(), generation: 3);

        $this->reap();
        $reaped = $fetch->fresh();
        $this->assertSame('failed', $reaped->status);
        $this->assertSame(4, $reaped->generation);

        Carbon::setTestNow(now()->addMinutes(16));

        $dispatched = app(FinancialStatementDispatcher::class)->dispatchFor($reaped->instrument);

        $this->assertTrue($dispatched);

        $redispatched = $reaped->fresh();
        $this->assertSame('queued', $redispatched->status);
        $this->assertSame(5, $redispatched->generation);
        $this->assertNull($redispatched->started_at);
        $this->assertSame(0, $redispatched->attempts);

        Carbon::setTestNow();
    }
}
