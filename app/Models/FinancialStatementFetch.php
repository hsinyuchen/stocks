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
     */
    public function markRunning(int $generation): bool
    {
        return DB::table($this->getTable())
            ->where('id', $this->id)
            ->where('generation', $generation)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'running',
                'started_at' => now(),
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

    /** 表有列但正在重抓——這是衍生顯示態，不是 DB enum 的一員。 */
    public function isInFlight(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
