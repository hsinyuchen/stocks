<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\LlmFailureReason;
use App\Models\NewsAnalysis;
use App\Models\StockAnalysis;
use App\Models\StockChatTurn;
use App\Models\WatchlistAnalysis;
use Illuminate\Database\Eloquent\Builder;
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
     * @return array{stock: int, news: int, chat: int, watchlist: int, jobs: int}
     */
    public function reap(): array
    {
        $minutes = max(1, (int) config('analysis.pending_timeout_minutes', 15));
        $cutoff = Carbon::now()->subMinutes($minutes);

        return [
            'stock' => $this->reapStock($cutoff),
            'news' => $this->reapNews($cutoff),
            'chat' => $this->reapChat($cutoff),
            'watchlist' => $this->reapWatchlist($cutoff),
            'jobs' => $this->discardStaleJobs($cutoff),
        ];
    }

    /**
     * 節流版本：超時是分鐘級事件，不需要每個 request 都掃一次。
     *
     * @return array{stock: int, news: int, chat: int, watchlist: int, jobs: int}|null null 代表這次跳過
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

        $reaped = 0;

        foreach ($rows as $row) {
            $failure = LlmFailureReason::Timeout->toArray();

            $reaped += $this->markFailed(StockAnalysis::query()->whereKey($row->getKey()), [
                'provider_type' => 'error',
                'llm_output' => $this->encode([
                    'provider' => 'error',
                    'model' => $row->model,
                    'content' => '分析排隊超過時限仍未執行，已自動結束。'.$failure['hint'],
                    'metadata' => ['error' => true, 'failure' => $failure, 'reaped' => true],
                ]),
            ]);
        }

        return $reaped;
    }

    private function reapNews(Carbon $cutoff): int
    {
        $rows = NewsAnalysis::query()
            ->where('status', AnalysisStatus::Pending->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        $reaped = 0;

        foreach ($rows as $row) {
            $failure = LlmFailureReason::Timeout->toArray();

            $reaped += $this->markFailed(NewsAnalysis::query()->whereKey($row->getKey()), [
                'provider_type' => 'error',
                'summary' => '分析排隊超過時限仍未執行，已自動結束。',
                'raw_output' => $this->encode(['error' => true, 'failure' => $failure, 'reaped' => true]),
            ]);
        }

        return $reaped;
    }

    private function reapWatchlist(Carbon $cutoff): int
    {
        $rows = WatchlistAnalysis::query()
            ->where('status', AnalysisStatus::Pending->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        $reaped = 0;

        foreach ($rows as $row) {
            $failure = LlmFailureReason::Timeout->toArray();

            $reaped += $this->markFailed(WatchlistAnalysis::query()->whereKey($row->getKey()), [
                'provider_type' => 'error',
                'summary' => '晚間快報排隊超過時限仍未執行，已自動結束。'.$failure['hint'],
                'raw_output' => $this->encode(['error' => true, 'failure' => $failure, 'reaped' => true]),
            ]);
        }

        return $reaped;
    }

    private function reapChat(Carbon $cutoff): int
    {
        $rows = StockChatTurn::query()
            ->where('status', AnalysisStatus::Pending->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        $reaped = 0;

        foreach ($rows as $row) {
            $failure = LlmFailureReason::Timeout->toArray();

            $reaped += $this->markFailed(StockChatTurn::query()->whereKey($row->getKey()), [
                'provider_type' => 'error',
                'answer' => '提問排隊超過時限仍未回答，已自動結束。'.$failure['hint'],
                'metadata' => $this->encode(['error' => true, 'failure' => $failure, 'reaped' => true]),
            ]);
        }

        return $reaped;
    }

    /**
     * 只在紀錄仍是 pending 時標記失敗。
     *
     * 讀取與寫入之間可能有 worker 正好把同一筆寫成完成——沒有這個條件的話，
     * 上面 get() 拿到的舊快照會把一次已經付費完成的回答覆寫成失敗。回傳實際
     * 影響的列數，讓計數反映真正被回收的筆數而不是候選筆數。
     *
     * @param  array<string, mixed>  $values
     */
    private function markFailed(Builder $query, array $values): int
    {
        return $query
            ->where('status', AnalysisStatus::Pending->value)
            ->update([
                ...$values,
                'status' => AnalysisStatus::Failed->value,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * json 欄位要自行序列化：這裡走 query builder 的 update，不經過 model 的 cast。
     *
     * @param  array<string, mixed>  $value
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
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
