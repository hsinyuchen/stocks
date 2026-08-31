<?php

namespace Tests\Feature\FinancialStatements;

use App\Jobs\FetchFinancialStatements;
use App\Models\User;
use App\Services\Analysis\InlineQueueWorker;
use App\Services\Analysis\StaleAnalysisReaper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatementsQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_statements_jobs_are_not_discarded_by_the_analysis_reaper(): void
    {
        // 這條是本 task 的核心。discardStaleJobs() 原本既不濾 queue 也不濾 class，
        // 財報 job 排隊逾 8 分鐘就會被當廢棄分析靜默刪除——沒有錯誤、沒有 log。
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'statements',
            'payload' => json_encode(['displayName' => FetchFinancialStatements::class]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subHour()->getTimestamp(),
            'created_at' => now()->subHour()->getTimestamp(),
        ]);

        app(StaleAnalysisReaper::class)->reap();

        $this->assertSame(1, DB::table('jobs')->where('queue', 'statements')->count());
    }

    public function test_analysis_reaper_still_discards_stale_default_queue_jobs(): void
    {
        // 迴歸：加過濾不得讓原本該清的東西留下來。
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\RunStockAnalysis']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subHour()->getTimestamp(),
            'created_at' => now()->subHour()->getTimestamp(),
        ]);

        app(StaleAnalysisReaper::class)->reap();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'default')->count());
    }

    public function test_queue_budgets_are_configured_not_hardcoded(): void
    {
        $queues = (array) config('analysis.queues');

        $this->assertArrayHasKey('statements', $queues);
        $this->assertArrayHasKey('default', $queues);
        $this->assertSame(1, $queues['statements']['max_jobs'], 'statements 每次 request 最多 1 筆');
    }

    public function test_schedule_runs_two_independent_workers(): void
    {
        // 單一 worker 的多佇列參數是嚴格優先序，無論哪一邊放前面都會餓死另一邊。
        $commands = collect(app(Schedule::class)->events())
            ->map(static fn ($event) => (string) $event->command)
            ->filter(static fn (string $command) => str_contains($command, 'queue:work'))
            ->values();

        $this->assertCount(2, $commands, 'statements 與 default 各一個獨立 worker');
        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, '--queue=statements')));
        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, '--queue=default')));
    }

    public function test_inline_worker_can_target_a_specific_queue(): void
    {
        $worker = app(InlineQueueWorker::class);

        $this->assertSame(0, $worker->pendingCount('statements'));
    }

    /**
     * 自加變異驗證用的正向測試：沒有這條，ProcessQueuedAnalyses 對 statements
     * 佇列到底有沒有真的被 drain 完全沒有測試覆蓋——日後若有人為了簡化把
     * statements 那段分支砍掉，既有測試全部維持綠燈也不會發現。
     *
     * instrumentId 故意給一個不存在的 id：FetchFinancialStatements::handle()
     * 在 instrument 找不到時直接 return（不丟例外），job 一樣會被視為執行完畢
     * 從佇列移除，不需要準備完整的 FinancialStatementFetch／Instrument 資料。
     */
    public function test_a_page_view_drains_the_statements_queue_via_inline_worker(): void
    {
        config(['queue.default' => 'database']);

        // dispatch() 而非 push($job)：後者不會讀 job 建構子裡 onQueue() 設好的
        // queue 屬性，插入的仍是連線預設佇列（實測踩到——push() 只有在明確傳入
        // $queue 參數或用 dispatch()／pushOn() 時才會用上 job 自帶的佇列名）。
        FetchFinancialStatements::dispatch(999999, 1);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'statements')->count());

        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'statements')->count());
    }

    /**
     * 自加變異驗證：drain() 的筆數上限若沒有真的依佇列各自讀
     * analysis.queues.{queue}.max_jobs（例如退化成永遠讀 inline_worker.max_jobs），
     * 前面幾個測試全部只排 1 筆 job，量不出差異，全部維持綠燈也不會發現。
     * 這裡刻意排 2 筆到 statements（配額 1），驗證只有 1 筆被清掉。
     */
    public function test_drain_respects_the_per_queue_max_jobs_budget(): void
    {
        config(['queue.default' => 'database', 'analysis.queues.statements.max_jobs' => 1]);

        FetchFinancialStatements::dispatch(999999, 1);
        FetchFinancialStatements::dispatch(999998, 1);
        $this->assertSame(2, DB::table('jobs')->where('queue', 'statements')->count());

        $worker = app(InlineQueueWorker::class);
        $processed = $worker->drain('statements');

        $this->assertSame(1, $processed, 'statements 配額是 1，一次 drain 只能處理 1 筆');
        $this->assertSame(1, DB::table('jobs')->where('queue', 'statements')->count(), '另一筆應該還留在佇列裡');
    }
}
