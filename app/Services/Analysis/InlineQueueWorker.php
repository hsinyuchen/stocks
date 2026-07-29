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
     * @return int 實際處理的 job 數
     */
    public function drain(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $maxSeconds = max(1, (int) config('analysis.inline_worker.max_seconds', 60));
        $maxJobs = max(1, (int) config('analysis.inline_worker.max_jobs', 2));

        $this->relaxTimeLimit($maxSeconds);

        $connection = config('queue.default');
        $queue = config("queue.connections.{$connection}.queue", 'default');

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
                $before = $this->pendingCount();

                if ($before === 0) {
                    break;
                }

                $this->worker()->runNextJob($connection, $queue, $options);
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
     */
    private function relaxTimeLimit(int $maxSeconds): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        $current = (int) ini_get('max_execution_time');

        if ($current === 0) {
            return;
        }

        if ($current < $this->requiredSeconds($maxSeconds)) {
            @set_time_limit($this->requiredSeconds($maxSeconds));
        }
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
     * 只在確定有待處理 job 時才進入 worker，避免每個 request 都付出建立連線的成本。
     */
    public function pendingCount(): int
    {
        try {
            return app('queue')->connection()->size(
                config('queue.connections.'.config('queue.default').'.queue', 'default'),
            );
        } catch (Throwable) {
            return 0;
        }
    }
}
