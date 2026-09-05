<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPrice extends Model
{
    protected $fillable = [
        'instrument_id',
        'priced_at',
        'open',
        'high',
        'low',
        'close',
        'volume',
    ];

    protected function casts(): array
    {
        return [
            'priced_at' => 'date:Y-m-d',
            'open' => 'decimal:4',
            'high' => 'decimal:4',
            'low' => 'decimal:4',
            'close' => 'decimal:4',
            'volume' => 'integer',
        ];
    }

    /**
     * 有實際成交的列。
     *
     * FinMind 對無成交日回 high＝low＝close＝0、open 沿用前值——那不是 K 棒。讀取端
     * 一律先過這個 scope：limit、涵蓋度列數、最新資料日期才不會被死列佔掉（先
     * limit 再丟會少回有效的根；只剩一列死棒時會被判成「新鮮且足量」卻回空序列）。
     * 寫入端保留上游原值，之後要追來源看得到；open 超界的修補在 OhlcRepair。
     */
    public function scopeTraded(Builder $query): Builder
    {
        return $query->where('close', '>', 0)->where('high', '>', 0)->where('low', '>', 0);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
