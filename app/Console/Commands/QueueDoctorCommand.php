<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Http\Middleware\ProcessQueuedAnalyses;
use App\Models\NewsAnalysis;
use App\Models\StockAnalysis;
use App\Services\Analysis\InlineQueueWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 診斷「分析永遠停在分析中」。
 *
 * 這個症狀的成因幾乎都在部署環境而不在程式：沒有人取件（沒有常駐 worker、沒有
 * 排程），或 inline 模式被 PHP 的執行時間上限砍掉。兩者從畫面上看完全一樣，所以
 * 把判斷需要的事實一次列出來，不必連進去翻日誌猜。
 */
class QueueDoctorCommand extends Command
{
    protected $signature = 'queue:doctor';

    protected $description = '檢查佇列與 AI 分析的執行環境，找出分析卡住的原因';

    public function handle(InlineQueueWorker $worker): int
    {
        $this->line('');
        $this->components->info('執行環境');

        // 這個指令跑在 CLI，而真正會砍掉 job 的是 web SAPI 的設定，兩者用不同的
        // ini 檔。web 端的值由 ProcessQueuedAnalyses 在請求中留下，這裡讀回來。
        $probe = Cache::get(ProcessQueuedAnalyses::RUNTIME_PROBE_KEY);

        $maxExecution = $probe === null
            ? (int) ini_get('max_execution_time')
            : (int) $probe['max_execution_time'];
        $canRelax = $probe === null
            ? function_exists('set_time_limit') && ! $this->isDisabled('set_time_limit')
            : (bool) $probe['set_time_limit_available'];

        if ($probe === null) {
            $this->components->warn(
                '尚未取得 web 端的執行環境（需要有人造訪過網站）。以下顯示的是 CLI 的值，'
                .'CLI 通常沒有時間限制，不能代表 PHP-FPM。請先開一次網站再跑這個指令。'
            );
            $this->kv('資料來源', 'CLI（'.PHP_SAPI.'）');
        } else {
            $this->kv('資料來源', 'web（'.$probe['sapi'].'，觀察於 '.$probe['observed_at'].'）');
        }

        $this->kv('max_execution_time', $maxExecution === 0 ? '0（無限制）' : $maxExecution.' 秒');
        $this->kv('set_time_limit 可用', $canRelax ? '是' : '否');
        $this->kv('queue driver', (string) config('queue.default'));
        $this->kv('inline worker', $worker->enabled() ? '啟用' : '停用');
        $this->kv('inline 時間預算', config('analysis.inline_worker.max_seconds').' 秒／次');
        $this->kv('LLM 逾時下限', config('analysis.llm_timeout_floor').' 秒');
        // 直接把「需要多少」印出來，使用者不必自己拿三個設定去算。
        $this->kv('最壞情況需要', $worker->requiredSeconds().' 秒');
        $this->kv('超時回收門檻', config('analysis.pending_timeout_minutes').' 分鐘');

        $this->line('');
        $this->components->info('佇列現況');

        $queueStats = $this->queueStats();

        foreach ($queueStats as $label => $value) {
            $this->kv($label, $value);
        }

        $this->line('');
        $this->components->info('分析紀錄');

        $stockPending = StockAnalysis::query()->where('status', AnalysisStatus::Pending->value)->count();
        $newsPending = NewsAnalysis::query()->where('status', AnalysisStatus::Pending->value)->count();
        $oldestPending = $this->oldestPendingMinutes();

        $this->kv('個股分析 pending', (string) $stockPending);
        $this->kv('新聞分析 pending', (string) $newsPending);
        $this->kv('最久的 pending', $oldestPending === null ? '—' : $oldestPending.' 分鐘');

        $this->line('');

        return $this->diagnose($worker, $maxExecution, $canRelax, $queueStats, $stockPending + $newsPending, $oldestPending);
    }

    /**
     * @return array<string, string>
     */
    private function queueStats(): array
    {
        if (config('queue.default') !== 'database') {
            return ['待處理 job' => '（非 database driver，無法統計）'];
        }

        $table = config('queue.connections.database.table', 'jobs');
        $pending = DB::table($table)->whereNull('reserved_at')->count();
        $reserved = DB::table($table)->whereNotNull('reserved_at')->count();
        $oldest = DB::table($table)->whereNull('reserved_at')->min('created_at');
        $failed = DB::table('failed_jobs')->count();

        return [
            '待處理 job' => (string) $pending,
            '執行中 job' => (string) $reserved,
            // 方向要從「過去 → 現在」算，反過來寫在 Carbon 3 會拿到負數。
            '最舊 job 已等待' => $oldest === null
                ? '—'
                : Carbon::createFromTimestamp($oldest)->diffInMinutes(Carbon::now()).' 分鐘',
            '失敗 job 累計' => (string) $failed,
        ];
    }

    private function oldestPendingMinutes(): ?int
    {
        $stock = StockAnalysis::query()->where('status', AnalysisStatus::Pending->value)->min('created_at');
        $news = NewsAnalysis::query()->where('status', AnalysisStatus::Pending->value)->min('created_at');

        $candidates = array_filter([$stock, $news]);

        if ($candidates === []) {
            return null;
        }

        return (int) Carbon::parse(min($candidates))->diffInMinutes(Carbon::now());
    }

    /**
     * @param  array<string, string>  $queueStats
     */
    private function diagnose(
        InlineQueueWorker $worker,
        int $maxExecution,
        bool $canRelax,
        array $queueStats,
        int $pendingAnalyses,
        ?int $oldestPending,
    ): int {
        $this->components->info('診斷');

        /** @var list<array{0: string, 1: string}> 每筆是「問題」與「處置」。 */
        $problems = [];

        if (! $worker->enabled() && config('queue.default') !== 'sync') {
            $problems[] = [
                'inline worker 已停用，沒有人會取件。',
                '請提供常駐 queue:work，或每分鐘執行 schedule:run。',
            ];
        }

        // 時間上限低於最壞情況所需，又不准用 set_time_limit 放寬。
        $required = $worker->requiredSeconds();

        if ($maxExecution > 0 && $maxExecution < $required && ! $canRelax) {
            $problems[] = [
                sprintf('max_execution_time 只有 %d 秒，最壞情況需要 %d 秒，且 set_time_limit 被停用。', $maxExecution, $required),
                sprintf(
                    '請主機商放寬到 %d，或降低 ANALYSIS_INLINE_WORKER_SECONDS 與 ANALYSIS_LLM_TIMEOUT_FLOOR 讓需求配合主機。',
                    $required,
                ),
            ];
        }

        $waited = (int) filter_var($queueStats['最舊 job 已等待'] ?? '', FILTER_SANITIZE_NUMBER_INT);

        if (($queueStats['待處理 job'] ?? '0') !== '0' && $waited > 5) {
            $problems[] = [
                sprintf('有 job 等待 %d 分鐘還沒被取走。', $waited),
                'inline 模式要有人瀏覽網站才會取件；網站沒有流量時分析不會前進。',
            ];
        }

        if ($pendingAnalyses > 0 && $oldestPending !== null
            && $oldestPending > (int) config('analysis.pending_timeout_minutes', 8)) {
            $problems[] = [
                sprintf('有分析 pending 了 %d 分鐘卻還沒被回收。', $oldestPending),
                '回收同樣靠 web request 觸發，代表這段期間沒有人造訪網站。',
            ];
        }

        if ($problems === []) {
            $this->line('  <fg=green>✓</> 目前沒有偵測到會讓分析卡住的問題');
            $this->line('');

            return self::SUCCESS;
        }

        // 不能用 twoColumnDetail——它的點線排版會把長句擠掉，讀者只會看到開頭
        // 幾個字。問題與處置分行，也避免主控台寬度把關鍵字折斷。
        foreach ($problems as [$problem, $action]) {
            $this->line('  <fg=yellow>▸</> '.$problem);
            $this->line('    '.$action);
            $this->line('');
        }

        return self::FAILURE;
    }

    private function isDisabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($function, $disabled, true);
    }

    private function kv(string $key, string $value): void
    {
        $this->components->twoColumnDetail($key, "<fg=cyan>{$value}</>");
    }
}
