<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * 擷取狀態列。所有轉態都是 compare-and-set。
 *
 * 每個轉態方法回傳 bool：true = CAS 命中；false = 這個 generation 已經過時，
 * 呼叫端**必須放棄**，不可改用「先讀再寫」繞過——那正是 CAS 要防的競態。
 */
class FinancialStatementFetch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'retry_after' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    /**
     * → running。
     *
     * 允許 queued → running（首次）與 running → running（Laravel 自動 retry 的
     * 第二次 attempt）。後者不可省：$tries = 2 時第一次 attempt 拋例外後 Laravel
     * 會把同一個 job release 回佇列（Worker.php:598），第二次執行時 DB 已是
     * running；矩陣若只允許 queued → running，第二次 CAS 匹配 0 列，$tries 是假的。
     *
     * `started_at` 只在 queued → running 這一段設定，running → running 刻意不碰。
     * 它的語意是「這個 generation 第一次開始執行的時間」，涵蓋的是整個 generation
     * 的生命週期——config('financial_statements.job.stale_after_seconds') 的
     * 240 秒＝tries × (timeout + backoff) + 60，算的就是這整段週期。若每次
     * attempt 都刷新，Task 8 reaper 的死亡判定會被順延到最壞 330 秒才觸發，
     * 跟這個算式對不上。重試次數已經有 `attempts` 記錄，`started_at` 不需要
     * 兼職表達它。兩段各自是原子的 CAS，依序嘗試：第一段沒中若是因為狀態已經
     * 是 running，第二段接手；兩段都沒中就代表這個 generation 已經過時。
     */
    public function markRunning(int $generation): bool
    {
        $table = $this->getTable();

        $firstAttempt = DB::table($table)
            ->where('id', $this->id)
            ->where('generation', $generation)
            ->where('status', 'queued')
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]) === 1;

        if ($firstAttempt) {
            return true;
        }

        return DB::table($table)
            ->where('id', $this->id)
            ->where('generation', $generation)
            ->where('status', 'running')
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * → succeeded / failed / unsupported。
     *
     * 只從 running 出發：沒有經過 running 就宣告終態，代表狀態被別人動過
     * （例如 reaper 判死後重新派工），這時的結果不可信。
     */
    public function markTerminal(
        int $generation,
        string $status,
        ?string $errorCategory = null,
        ?CarbonInterface $retryAfter = null,
    ): bool {
        return DB::table($this->getTable())
            ->where('id', $this->id)
            ->where('generation', $generation)
            ->where('status', 'running')
            ->update([
                'status' => $status,
                'error_category' => $errorCategory,
                'retry_after' => $retryAfter,
                'finished_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * 表有列但正在重抓——這是衍生顯示態，不是 DB enum 的一員。
     *
     * 服務 Task 5 的 FinancialStatementsReader：區分「fetching」（沒有舊資料，
     * 第一次抓）與「refreshing」（已有列、正在重抓）兩種顯示態，靠的正是
     * 「這筆有沒有舊資料」加上這個方法。刻意不進 DB enum——enum 只記錄擷取本身
     * 的狀態，「要不要疊加舊資料」是呈現層的判斷，不該讓 DB schema 背這個責任。
     */
    public function isInFlight(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
