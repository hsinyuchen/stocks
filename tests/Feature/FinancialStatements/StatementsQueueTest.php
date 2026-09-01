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

    /**
     * 迴歸測試：drain() 查配額時必須用「解析後的佇列名」（$resolvedQueue），
     * 不能用呼叫端傳入的原始 $queue。呼叫端傳 null（例如 ProcessQueuedAnalyses
     * 對 default 佇列的呼叫）時，若誤用原始 $queue 去查
     * config("analysis.queues.{$queue}.max_jobs")，等於查
     * "analysis.queues..max_jobs"（永遠查不到鍵），會無條件退回
     * analysis.inline_worker.max_jobs，讓 analysis.queues.default.max_jobs
     * 這個設定形同虛設。
     *
     * 兩個配額刻意設成不同值（3 與 99）：config/analysis.php 的預設值剛好都是 2，
     * 若沿用預設值測，退化成查 inline_worker.max_jobs 也會巧合地算出同樣的
     * 處理筆數，完全看不出兩條路徑的差異——這正是這條規則先前沒有被任何測試
     * 抓到的原因（審查實測：把 drain() 裡的 $resolvedQueue 改回 $queue，
     * 原本 44 個相關測試全部維持綠燈）。
     */
    public function test_drain_with_no_argument_reads_the_resolved_default_queue_budget(): void
    {
        config([
            'queue.default' => 'database',
            // 借用既有的 no-op 財報 job（instrumentId 不存在時 handle() 直接
            // return）當快速 job，只是把它改派到 default 佇列，藉此驗證
            // default 佇列自己的配額，而不是 statements 的。
            'financial_statements.job.queue' => 'default',
            'analysis.queues.default.max_jobs' => 3,
            'analysis.inline_worker.max_jobs' => 99,
            // 秒數預算要夠寬，確保迴圈是被筆數配額擋下、不是被秒數預算擋下，
            // 否則斷言驗到的就不是這條規則。
            'analysis.inline_worker.max_seconds' => 60,
        ]);

        for ($i = 0; $i < 5; $i++) {
            FetchFinancialStatements::dispatch(999999, 1);
        }
        $this->assertSame(5, DB::table('jobs')->where('queue', 'default')->count());

        $processed = app(InlineQueueWorker::class)->drain();

        $this->assertSame(
            3,
            $processed,
            '應讀到 analysis.queues.default.max_jobs=3；讀到 99 代表退化成查 inline_worker.max_jobs，讀到 5 代表兩個配額都沒生效'
        );
        $this->assertSame(2, DB::table('jobs')->where('queue', 'default')->count(), '5 筆排隊、配額 3，應剩 2 筆');
    }

    /**
     * Minor：analysis.queues.{佇列名}.max_jobs 鍵完全不存在時的 fallback 路徑。
     * 用一個 config/analysis.php 沒定義的佇列名，確認會退回
     * analysis.inline_worker.max_jobs，而不是拋錯或退成 0。
     */
    public function test_drain_falls_back_to_inline_worker_max_jobs_for_an_unconfigured_queue(): void
    {
        config([
            'queue.default' => 'database',
            'financial_statements.job.queue' => 'unconfigured-queue',
            'analysis.inline_worker.max_jobs' => 2,
            'analysis.inline_worker.max_seconds' => 60,
        ]);
        // 確保這個佇列名在 analysis.queues 底下真的沒有設定過，落到 fallback 分支。
        $this->assertArrayNotHasKey('unconfigured-queue', (array) config('analysis.queues'));

        for ($i = 0; $i < 3; $i++) {
            FetchFinancialStatements::dispatch(999999, 1);
        }
        $this->assertSame(3, DB::table('jobs')->where('queue', 'unconfigured-queue')->count());

        $processed = app(InlineQueueWorker::class)->drain('unconfigured-queue');

        $this->assertSame(2, $processed, '沒有專屬設定時應退回 inline_worker.max_jobs=2');
    }
}
