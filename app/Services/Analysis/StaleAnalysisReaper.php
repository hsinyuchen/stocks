<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\LlmFailureReason;
use App\Models\NewsAnalysis;
use App\Models\StockAnalysis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 回收放棄等待的分析。
 *
 * 沒有這道防線時，只要沒有人取件（沒有常駐 worker、也沒有排程），紀錄就永遠停在
 * pending，前端也就永遠輪詢下去——使用者看到的是一個不會結束的「分析中」。
 *
 * 超時的 job 一併從佇列刪除：留著只會在下次有人瀏覽網站時被 inline worker 取出，
 * 對著早已被放棄的分析再打一次 LLM，白花錢。
 */
class StaleAnalysisReaper
{
    private const THROTTLE_KEY = 'analysis:reaper:last-run';

    /**
     * @return array{stock: int, news: int, jobs: int}
     */
    public function reap(): array
    {
        $minutes = max(1, (int) config('analysis.pending_timeout_minutes', 15));
        $cutoff = Carbon::now()->subMinutes($minutes);

        return [
            'stock' => $this->reapStock($cutoff),
            'news' => $this->reapNews($cutoff),
            'jobs' => $this->discardStaleJobs($cutoff),
        ];
    }

    /**
     * 節流版本：超時是分鐘級事件，不需要每個 request 都掃一次。
     *
     * @return array{stock: int, news: int, jobs: int}|null null 代表這次跳過
     */
    public function reapThrottled(): ?array
    {
        $seconds = max(1, (int) config('analysis.reaper_throttle_seconds', 60));

        // add() 是原子操作：併發 request 只有第一個拿得到，其餘直接跳過。
        if (! Cache::add(self::THROTTLE_KEY, true, $seconds)) {
            return null;
        }

        return $this->reap();
    }

    private function reapStock(Carbon $cutoff): int
    {
        $rows = StockAnalysis::query()
            ->where('status', AnalysisStatus::Pending->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($rows as $row) {
            $failure = LlmFailureReason::Timeout->toArray();

            $row->forceFill([
                'status' => AnalysisStatus::Failed,
                'provider_type' => 'error',
                'llm_output' => [
                    'provider' => 'error',
                    'model' => $row->model,
                    'content' => '分析排隊超過時限仍未執行，已自動結束。'.$failure['hint'],
                    'metadata' => ['error' => true, 'failure' => $failure, 'reaped' => true],
                ],
            ])->save();
        }

        return $rows->count();
    }

    private function reapNews(Carbon $cutoff): int
    {
        $rows = NewsAnalysis::query()
            ->where('status', AnalysisStatus::Pending->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($rows as $row) {
            $failure = LlmFailureReason::Timeout->toArray();

            $row->forceFill([
                'status' => AnalysisStatus::Failed,
                'provider_type' => 'error',
                'summary' => '分析排隊超過時限仍未執行，已自動結束。',
                'raw_output' => ['error' => true, 'failure' => $failure, 'reaped' => true],
            ])->save();
        }

        return $rows->count();
    }

    /**
     * 丟掉過期的佇列項目。
     *
     * 以 jobs.created_at 為準而不是比對 analysis id：payload 是序列化字串，逐筆
     * 解析既慢又脆弱，而「入列超過時限」本身就足以判定作廢。
     */
    private function discardStaleJobs(Carbon $cutoff): int
    {
        if (config('queue.default') !== 'database') {
            return 0;
        }

        $table = config('queue.connections.database.table', 'jobs');

        // reserved_at 不為 null 代表某個 worker 正在跑它，不能中途抽掉。
        return DB::table($table)
            ->whereNull('reserved_at')
            ->where('created_at', '<', $cutoff->getTimestamp())
            ->delete();
    }
}
