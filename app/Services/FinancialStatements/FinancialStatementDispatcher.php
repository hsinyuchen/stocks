<?php

namespace App\Services\FinancialStatements;

use App\Console\Commands\WarmFinancialStatements;
use App\Jobs\FetchFinancialStatements;
use App\Models\FinancialStatement;
use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 決定要不要派工，並把 generation 遞增這件事做成原子操作。
 *
 * 第一步用 INSERT IGNORE 而不是 INSERT ... ON DUPLICATE KEY UPDATE：MySQL 的
 * ODKU 子句**不支援 WHERE**，列已存在且為 running 時會被無條件覆寫成 queued，
 * 直接打穿 CAS。IGNORE 讓「列已存在」落到第二步的條件 UPDATE 去判。
 *
 * 第二步刻意用「交易 + SELECT ... FOR UPDATE」而不是「條件 UPDATE 之後再讀一次
 * generation」：後者雖然在目前程式碼下是安全的（generation 欄位只有本類別會寫，
 * 條件 UPDATE 命中後該列已經是 queued，不再落在允許集合裡，不會被第三方搶先再
 * 動一次），但這個安全性依賴「未來也沒有其他寫入路徑碰 generation」這個隱性假設，
 * 一旦有人加了新路徑就會悄悄破功。鎖住列之後在同一個交易內讀取、判斷、寫入，
 * 正確性不必依賴這個假設。
 */
class FinancialStatementDispatcher
{
    public function dispatchFor(Instrument $instrument): bool
    {
        $generation = $this->claim($instrument);

        if ($generation === null) {
            return false;
        }

        // commit 之後才 dispatch。dispatch 失敗時狀態停在 queued 而沒有任何 job，
        // 由 reaper 的 stale-queued 收割接手——否則後續派工又因 queued 不在允許
        // 集合而被擋，永久死鎖。
        FetchFinancialStatements::dispatch($instrument->id, $generation);

        return true;
    }

    /**
     * @return int|null 取得的 generation；null = 不該派工
     *
     * @see WarmFinancialStatements 這段 INSERT IGNORE ＋
     *      條件 UPDATE 的 CAS 邏輯在該指令的 claim() 有一份刻意的複製（此方法
     *      是 private，且預熱不能走 dispatchFor() 的 dispatch() 進佇列）。改
     *      這裡的 claim 語意（新增欄位重置、generation 遞增規則、succeeded 的
     *      新鮮度判斷）時，記得同步檢查那邊有沒有跟著改——目前 Warm 那邊已經
     *      用 skipReason() 在呼叫 claim() 之前擋掉新鮮的 succeeded，但它的
     *      claim() 複製本身沒有這道防線，純粹依賴呼叫順序，不是獨立防呆。
     */
    private function claim(Instrument $instrument): ?int
    {
        $table = (new FinancialStatementFetch)->getTable();
        $now = now()->toDateTimeString();

        if ($this->insertIgnore($table, $instrument->id, $now) === 1) {
            return 1;
        }

        // 列已存在。只有終態、且退避已到期才能重新排隊。鎖住這一列避免
        // 「先讀再判斷再寫」在多個並發請求下互相踩過。
        return DB::transaction(function () use ($table, $instrument, $now): ?int {
            $row = DB::table($table)
                ->where('instrument_id', $instrument->id)
                ->lockForUpdate()
                ->first();

            // INSERT IGNORE 回報「列已存在」，這裡理論上一定查得到；寫成防禦
            // 而不是斷言，避免極端情況下讓整個請求 500。
            if ($row === null) {
                return null;
            }

            $isTerminal = in_array($row->status, ['succeeded', 'failed', 'unsupported'], true);
            $retryDue = $row->retry_after === null
                || Carbon::parse($row->retry_after)->lessThanOrEqualTo(now());

            if (! $isTerminal || ! $retryDue) {
                return null;
            }

            // succeeded 的 retry_after 恆為 null（見 FetchFinancialStatements::finish()，
            // 只有 failed／unsupported 才會設退避），上面的 retryDue 判準對它永遠成立。
            // 新鮮度因此不能靠 retry_after，spec 明文交給表列的 fetched_at＋30 天
            // 新鮮度窗口決定——沒有這一步，任何 dispatchFor() 呼叫（包括單純的
            // 頁面渲染）都會對新鮮資料再重派一次工。
            if ($row->status === 'succeeded' && $this->isFresh($instrument)) {
                return null;
            }

            $newGeneration = $row->generation + 1;

            DB::table($table)
                ->where('id', $row->id)
                ->update([
                    'generation' => $newGeneration,
                    'status' => 'queued',
                    'queued_at' => $now,
                    'started_at' => null,
                    'finished_at' => null,
                    // 舊的失敗原因不該留著誤導畫面。
                    'error_category' => null,
                    'attempts' => 0,
                    'updated_at' => $now,
                ]);

            return $newGeneration;
        });
    }

    /**
     * succeeded 是否還在新鮮期內，不需要重派工。
     *
     * 規則與 {@see FinancialStatementsReader::isStale()} 同一套（跨列取最新、
     * 跨欄取最舊），不重新定義一份——那正是這批財報實際顯示給使用者看時用的
     * 新鮮度判準，dispatch 決策與畫面顯示不同調只會讓「畫面說過期了」與
     * 「這次瀏覽卻不重抓」互相矛盾。
     *
     * 完全沒有表列（抓成功但上游零期間，見 FetchFinancialStatements 對空
     * PeriodFactSet 記的 error_category='no_data'）時**不能**直接呼叫
     * isStale()：空陣列時 isStale() 回傳 false（見該方法 docblock，這是
     * 「無法判斷」的中性表態，不是「新鮮」），若在這裡當作新鮮處理，這種
     * 「成功但永遠沒有資料」的標的會永久卡死、再也沒有機會重試。
     *
     * 這裡刻意查全部列、不篩 period_type、不 limit——因為這裡問的是「這檔
     * 標的要不要重抓」，是整檔的事，不是單一分頁的事。不要為了「跟
     * {@see FinancialStatementsReader::for()} 的輸入一致」而改成篩過的列，
     * 理由見 {@see FinancialStatementsReader::isStale()} docblock。
     */
    private function isFresh(Instrument $instrument): bool
    {
        $periods = FinancialStatement::query()->where('instrument_id', $instrument->id)->get();

        if ($periods->isEmpty()) {
            return false;
        }

        return ! FinancialStatementsReader::isStale($periods);
    }

    /**
     * sqlite（測試）不接受 MySQL 的 `INSERT IGNORE` 語法，等價寫法是
     * `INSERT OR IGNORE`；兩者語意相同：違反唯一鍵時靜默跳過，不拋錯也不覆寫。
     */
    private function insertIgnore(string $table, int $instrumentId, string $now): int
    {
        $verb = DB::connection()->getDriverName() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        return DB::affectingStatement(
            "{$verb} INTO {$table}
                (instrument_id, generation, status, attempts, queued_at, created_at, updated_at)
             VALUES (?, 1, 'queued', 0, ?, ?, ?)",
            [$instrumentId, $now, $now, $now]
        );
    }
}
