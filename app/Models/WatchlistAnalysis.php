<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchlistAnalysis extends Model
{
    /**
     * user_id 刻意排除在外，與其他 user-owned 模型一致（Watchlist、Holding、
     * StockAnalysis、NewsAnalysis、LlmProviderSetting）。呼叫點都走關聯 create()，
     * 本就不會塞 user_id；少了這道護欄，日後 mass-assign 就能指定他人的 user_id。
     */
    protected $fillable = [
        'provider_type',
        'model',
        'prompt_version',
        'status',
        'summary',
        'payload',
        'raw_output',
        'related_symbols',
        'data_as_of',
    ];

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'payload' => 'array',
            'raw_output' => 'array',
            'related_symbols' => 'array',
            'data_as_of' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
