<?php

namespace App\Services\FinancialStatements;

use App\Models\FinancialStatementFetch;
use Illuminate\Support\Facades\DB;

/**
 * 收割卡住的擷取。
 *
 * 死亡判定不能只靠 reader 被動判斷：沒有人瀏覽的標的會永遠停在 running 或 queued。
 *
 * 必須同時收割 queued——CAS commit 之後、dispatch() 之前若程序崩潰，或 dispatch
 * 本身失敗（佇列連線異常、序列化失敗），狀態會停在 queued 而沒有任何 job，後續
 * 派工又因 queued 不在允許集合而被擋，永久死鎖。條件用 COALESCE(started_at,
 * queued_at) 涵蓋兩態——queued 狀態的 started_at 恆為 null。
 *
 * 必須遞增 generation——只改 status 的話，被判死的 worker 若其實還活著，之後仍能
 * 用原 generation 寫入 succeeded，把判定蓋掉。
 *
 * 只改 status／generation／retry_after，刻意不動 started_at 與 attempts：這兩欄
 * 在終態下是死資料，之後若退避到期被 FinancialStatementDispatcher::claim() 重新
 * 排隊，會被那裡重置為 null／0（見該類別的 claim()）。兩邊各自的職責邊界要維持
 * 一致，任一邊改了假設都會讓死鎖用另一種形式回來。
 */
class StaleFetchReaper
{
    public function reap(): int
    {
        $threshold = now()->subSeconds((int) config('financial_statements.job.stale_after_seconds'));

        return DB::table((new FinancialStatementFetch)->getTable())
            ->whereIn('status', ['running', 'queued'])
            ->whereRaw('COALESCE(started_at, queued_at) < ?', [$threshold])
            ->update([
                'status' => 'failed',
                'error_category' => 'timeout',
                'generation' => DB::raw('generation + 1'),
                'finished_at' => now(),
                // 判死之後要能重派，但不能立刻重派——否則死一次就無限重打上游。
                'retry_after' => now()->addMinutes((int) config('financial_statements.retry_after.failed_minutes')),
                'updated_at' => now(),
            ]);
    }
}
