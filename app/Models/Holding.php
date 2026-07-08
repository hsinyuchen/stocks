<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holding extends Model
{
    /**
     * user_id 與 currency 刻意不可 mass assign：
     * user_id 經 relation save 補上，currency 由 MarketResolver 伺服端判定。
     */
    protected $fillable = [
        'instrument_id',
        'shares',
        'avg_cost',
        'note',
    ];

    protected function casts(): array
    {
        // 注意：decimal cast 回傳 string，取用端須顯式 (float) 轉型。
        return [
            'shares' => 'decimal:4',
            'avg_cost' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
