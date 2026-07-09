<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    /**
     * user_id 與觸發狀態欄位刻意不可 mass assign：
     * user_id 經 relation save 補上；status/triggered_* 由 AlertEvaluator
     * 伺服端控制，不接受使用者輸入。
     */
    protected $fillable = [
        'instrument_id',
        'type',
        'threshold',
        'signal_key',
        'note',
    ];

    protected function casts(): array
    {
        // decimal cast 回傳 string，取用端須顯式 (float)。
        return [
            'threshold' => 'decimal:4',
            'triggered_price' => 'decimal:4',
            'triggered_at' => 'datetime',
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
