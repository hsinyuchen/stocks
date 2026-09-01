<?php

namespace App\Services\Analysis;

use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 在 web request 的回應送出之後，就地消化佇列。
 *
 * 為什麼需要：部署環境不一定能跑常駐 worker，也不一定有 cron。少了取件的人，
 * job 會永遠留在 jobs 表，分析就永遠停在「分析中」。這裡把每個 request 當成一次
 * 取件機會——回應早就送出了，使用者不會等。
 *
 * 限制要說清楚：受 PHP 的 max_execution_time 影響，長 prompt 可能中途被砍。能跑
 * 常駐 worker 或 schedule:run 的環境應該關掉 `analysis.inline_worker.enabled`。
 */
class InlineQueueWorker
{
    /**
     * Worker 不能直接由容器自動解析（建構子帶著 isDownForMaintenance 之類的
     * closure），一律取 QueueServiceProvider 註冊好的 'queue.worker' 單例。
     */
    private function worker(): Worker
    {
        return app('queue.worker');
    }

    public function enabled(): bool
    {
        return (bool) config('analysis.inline_worker.enabled', true);
    }

    /**
     * 盡量在時間與筆數預算內清掉佇列。
     *
     * @param  string|null  $queue  指定佇列名；null 時解析成
     *                              queue.connections.{connection}.queue（也就是
     *                              DB_QUEUE 目前的值，非字面量 'default'）。筆數
     *                              上限一律依「解析後的佇列名」從
     *                              analysis.queues.{resolved}.max_jobs 讀取，
     *                              該鍵不存在時退回 analysis.inline_worker.max_jobs
     *                              ——呼叫端可能分段對多個佇列各呼叫一次
     *                              drain()，見 ProcessQueuedAnalyses。
     * @return int 實際處理的 job 數
     */
    public function drain(?string $queue = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $maxSeconds = max(1, (int) config('analysis.inline_worker.max_seconds', 60));

        $this->relaxTimeLimit($maxSeconds);

        $connection = config('queue.default');
        $resolvedQueue = $queue ?? self::resolveDefaultQueueName();

        // 配額一律照「解析後的真實佇列名」查，不是照呼叫端傳的 $queue（可能是
        // null）。這樣呼叫端傳 null 時，也能查到 default 佇列自己的
        // analysis.queues.default.max_jobs，而不是退回去籠統的
        // analysis.inline_worker.max_jobs——後者會讓 Task 9 建的配額設定形同虛設。
        $maxJobs = max(1, (int) config("analysis.queues.{$resolvedQueue}.max_jobs", config('analysis.inline_worker.max_jobs', 2)));

        // maxTries=1 與 job 自身的 $tries 一致：LLM 呼叫昂貴且非冪等，逾時重跑
        // 只會把上游的壅塞再放大一次。
        $options = new WorkerOptions(
            timeout: $maxSeconds,
            sleep: 0,
            maxTries: 1,
            stopWhenEmpty: true,
        );

        $deadline = microtime(true) + $maxSeconds;
        $processed = 0;

        while ($processed < $maxJobs && microtime(true) < $deadline) {
            try {
                // runNextJob 自己會處理失敗與 failed_jobs 寫入；佇列空時直接返回。
                $before = $this->pendingCount($queue);

                if ($before === 0) {
                    break;
                }

                $this->worker()->runNextJob($connection, $resolvedQueue, $options);
            } catch (Throwable $exception) {
                // 這裡炸掉不能影響任何使用者可見行為——回應早就送出去了。
                Log::warning('inline queue worker: job failed', ['error' => $exception->getMessage()]);

                break;
            }

            $processed++;
        }

        return $processed;
    }

    /**
     * 放寬 PHP 的執行時間上限。
     *
     * 回應已經送出，剩下的都是背景工作，web SAPI 常見的 30～60 秒上限會在 LLM
     * 回來之前先砍掉整個進程。
     *
     * 只放寬、不縮短：CLI 的 max_execution_time 是 0（無限），無條件呼叫
     * set_time_limit 會反過來替它加上限制——實測就是這樣把一個跑到 90 秒的
     * 分析砍掉的。共享主機可能停用 set_time_limit，失敗就算了，這只是盡力而為。
     *
     * 每次呼叫都要無條件重啟倒數（$current > 0 時）,不能只在「目前值不夠大」時
     * 才動：set_time_limit() 是從呼叫當下重新計時，不是把上限記成某個數字。
     * ProcessQueuedAnalyses 對 statements、default 兩個佇列各呼叫一次 drain()，
     * 若只在需要放寬時才呼叫，第一段 drain 設定的那份計時器會被第二段 drain
     * 沿用——第二段實際能跑的時間等於「原本的上限－第一段已經耗掉的秒數」，
     * 而不是它自己該有的完整預算。實測踩到：statements job 跑 55 秒後，
     * default 段最壞只剩 155 秒可用，卻要應付最壞 210 秒的 LLM 分析，
     * 進程會在完成前被 PHP 直接砍斷（fatal，RunStockAnalysis::failed() 不會
     * 被呼叫，分析卡在 pending 直到 8 分鐘後才被 reaper 標失敗）。
     * 用 max($current, required) 而非直接覆寫，維持「只放寬不縮短」。
     */
    private function relaxTimeLimit(int $maxSeconds): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        $current = (int) ini_get('max_execution_time');
        $target = $this->nextTimeLimit($current, $this->requiredSeconds($maxSeconds));

        if ($target !== null) {
            @set_time_limit($target);
        }
    }

    /**
     * 決定這次呼叫要不要重啟倒數、目標秒數是多少。抽成獨立的純函式純粹是為了
     * 可測試性：set_time_limit() 被呼叫這件事的效果是「重啟倒數」，PHP 沒有
     * 任何事後能觀察的手段去確認倒數是否被重啟過（ini_get() 讀到的只是設定值，
     * 不是剩餘秒數，呼叫前後可能是同一個數字），只能靠斷言這個方法的回傳值
     * 來驗證「該重啟的時候真的會回傳非 null」。
     *
     * $current === 0（無限制）回傳 null：不能被無條件重啟蓋成有限制的
     * max_execution_time，那是退步，不是放寬。
     *
     * 其餘情況一律回傳 max($current, $required)，不是只在「目前值不夠大」時
     * 才回傳非 null——那正是 I-1 的 bug：ProcessQueuedAnalyses 對 statements、
     * default 兩個佇列各呼叫一次 drain()，若只在需要放寬時才觸發
     * set_time_limit()，第二段 drain 會沿用第一段已經跑掉一部分的舊倒數視窗，
     * 而不是拿到它自己該有的完整預算。實測踩到的失效路徑：statements job 跑
     * 55 秒後，default 段最壞只剩 155 秒可用，卻要應付最壞 210 秒的 LLM
     * 分析，進程會在完成前被 PHP 直接砍斷（fatal，RunStockAnalysis::failed()
     * 不會被呼叫，分析卡在 pending 直到 8 分鐘後才被 reaper 標失敗）。用
     * max() 而非直接覆寫，是為了在「無條件重啟」與「只放寬不縮短」之間兩者
     * 兼顧。
     */
    private function nextTimeLimit(int $current, int $required): ?int
    {
        if ($current === 0) {
            return null;
        }

        return max($current, $required);
    }

    /**
     * 最壞情況需要的秒數。
     *
     * 迴圈只在預算內起跑新 job，但起跑後那一筆仍要完整跑完，所以是
     * 「預算 + 單筆上限」而不是「預算」。單筆上限取 LLM 逾時下限（實際逾時只會
     * 更大或相等）再加行情抓取與寫入的餘裕。
     *
     * 寫死 150 會和設定脫節：把 llm_timeout_floor 降到 90 的環境（受限主機）
     * 仍會要到不必要的高值，反而更容易撞上主機的硬上限。
     */
    public function requiredSeconds(?int $maxSeconds = null): int
    {
        $maxSeconds ??= max(1, (int) config('analysis.inline_worker.max_seconds', 60));
        $llmTimeout = max(1, (int) config('analysis.llm_timeout_floor', 120));

        return $maxSeconds + $llmTimeout + 30;
    }

    /**
     * 「default 佇列真正叫什麼」的唯一算式。
     *
     * DB_QUEUE 被改名時，凡是沒有呼叫這個方法、自己另外寫一份判斷式的地方，
     * 都會排錯佇列名讓分析 job 永遠取不到件（inline 模式尤其致命，見
     * relaxTimeLimit() 的說明）。已知呼叫這裡的地方：本類別的 drain()／
     * pendingCount()、StaleAnalysisReaper::discardStaleJobs()、
     * routes/console.php 的第二個 queue:work、QueueDoctorCommand 的逐佇列統計。
     *
     * 兩處已知例外、刻意不呼叫這裡：composer.json 的 `composer dev` 腳本是純
     * JSON 字串，無法在其中內嵌 PHP 運算；config/analysis.php 的
     * `queues.default` 是設定檔的陣列鍵名，而非執行期資料，且該檔案在
     * bootstrap 階段可能早於 config/queue.php 被載入，此時呼叫這裡會讀到
     * 尚未就緒的設定。DB_QUEUE 被改名時這兩處仍會用字面量 'default'——前者
     * 只影響本機開發體驗，後者的後果是 `analysis.queues.{解析後佇列名}.max_jobs`
     * 查不到鍵而退回較籠統的 `analysis.inline_worker.max_jobs`（有測試覆蓋，
     * 是已知、可接受的降級，不是錯誤）。
     */
    public static function resolveDefaultQueueName(): string
    {
        return (string) config('queue.connections.'.config('queue.default').'.queue', 'default');
    }

    /**
     * 兩段 drain（statements、default）合計的最壞情況秒數，供 queue:doctor
     * 判斷「主機的 max_execution_time 夠不夠、set_time_limit 又被停用時會不會
     * 被砍」。
     *
     * 曾經在 QueueDoctorCommand 私有方法與這裡（當時的 requiredSeconds()）各算
     * 一次，兩份算式的參數不同步（一個沒算進 statements 上限）正是 I-1 那個
     * bug 的溫床——這裡是唯一算式，QueueDoctorCommand 呼叫它。
     */
    public function worstCaseSeconds(): int
    {
        $statementsCap = max(1, (int) config('financial_statements.job.timeout', 60));

        return $statementsCap + $this->requiredSeconds();
    }

    /**
     * 只在確定有待處理 job 時才進入 worker，避免每個 request 都付出建立連線的成本。
     *
     * @param  string|null  $queue  指定佇列名；null 時維持既有預設佇列行為。
     */
    public function pendingCount(?string $queue = null): int
    {
        try {
            $resolvedQueue = $queue ?? self::resolveDefaultQueueName();

            return app('queue')->connection()->size($resolvedQueue);
        } catch (Throwable) {
            return 0;
        }
    }
}
