<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fundamental extends Model
{
    protected $fillable = [
        'instrument_id',
        'per', 'pbr', 'dividend_yield', 'eps', 'roe',
        'revenue', 'revenue_yoy',
        'eps_quarter', 'revenue_month', 'data_as_of', 'fetched_at',
    ];

    /** 指標欄位（判斷「全 null 列」= 抓取失敗，走 failure TTL）。 */
    public const METRIC_COLUMNS = ['per', 'pbr', 'dividend_yield', 'eps', 'roe', 'revenue', 'revenue_yoy'];

    protected function casts(): array
    {
        // decimal cast 回傳 string，取用端須顯式 (float)。
        return [
            'per' => 'decimal:4', 'pbr' => 'decimal:4', 'dividend_yield' => 'decimal:4',
            'eps' => 'decimal:4', 'roe' => 'decimal:4',
            'revenue' => 'decimal:4', 'revenue_yoy' => 'decimal:4',
            'eps_quarter' => 'date', 'revenue_month' => 'date', 'data_as_of' => 'date',
            'fetched_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
