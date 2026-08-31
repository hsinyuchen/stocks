<?php

namespace App\Http\Middleware;

use App\Services\Analysis\InlineQueueWorker;
use App\Services\Analysis\StaleAnalysisReaper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

use function Illuminate\Support\defer;

/**
 * 讓佇列在沒有常駐 worker 的環境下也能前進。
 *
 * 兩件事都排在回應送出之後（defer 的 callback 由 InvokeDeferredCallbacks 在
 * terminate 階段執行），所以完全不影響使用者感受到的回應時間：
 *
 * 1. 回收超時仍是 pending 的分析，順便丟掉過期的 job。
 * 2. 取一兩個 job 出來跑，把「沒有人取件」的死結解開。
 */
class ProcessQueuedAnalyses
{
    /** queue:doctor 從這裡讀 web 端的實際執行環境。 */
    public const RUNTIME_PROBE_KEY = 'analysis:web-runtime';

    public function __construct(
        private readonly InlineQueueWorker $worker,
        private readonly StaleAnalysisReaper $reaper,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 只有一般頁面瀏覽適合當取件時機。分析輪詢每 3 秒一次，讓它也去搶 job
        // 只會讓同一批工作被反覆嘗試；而 Inertia 的部分重載本來就該輕。
        if ($this->shouldSkip($request)) {
            return $response;
        }

        defer(function (): void {
            $this->recordWebRuntime();
            $this->reaper->reapThrottled();

            if (! $this->worker->enabled()) {
                return;
            }

            // 分兩段各自取件，先 statements 再 default——不是「剩餘秒數預算給
            // default」。秒數預算只是「是否開始下一個 job」的判斷，不是執行中的
            // 硬上限；FetchFinancialStatements 的 timeout 是 60 秒，單一 statements
            // job 就可能吃掉整個 request 的時間，讓 default 那段秒數預算根本不存在。
            // 能保證的只有各自的 max_jobs 筆數（config('analysis.queues')）。
            if ($this->worker->pendingCount('statements') > 0) {
                $this->worker->drain('statements');
            }

            if ($this->worker->pendingCount('default') > 0) {
                $this->worker->drain('default');
            }
        });

        return $response;
    }

    /**
     * 記下 web 端真正的執行時間限制，供 queue:doctor 顯示。
     *
     * 診斷指令跑在 CLI，而 CLI 的 max_execution_time 通常是 0（無限）——直接印出來
     * 會讓人以為環境沒問題，但真正會砍掉 job 的是 PHP-FPM 那一份設定。兩者用不同
     * 的 ini，CLI 讀不到，只能由 web request 自己留下紀錄。
     */
    private function recordWebRuntime(): void
    {
        // 這些值不會變動，一小時記一次就夠，不必每個 request 都寫 cache。
        if (! Cache::add(self::RUNTIME_PROBE_KEY.':throttle', true, 3600)) {
            return;
        }

        Cache::put(self::RUNTIME_PROBE_KEY, [
            'sapi' => PHP_SAPI,
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'set_time_limit_available' => function_exists('set_time_limit')
                && ! in_array('set_time_limit', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true),
            'observed_at' => now()->toDateTimeString(),
        ], now()->addDays(7));
    }

    private function shouldSkip(Request $request): bool
    {
        // Inertia 的部分重載（輪詢）帶這個標頭。
        return $request->hasHeader('X-Inertia-Partial-Data');
    }
}
