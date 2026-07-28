<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsAnalysis extends Model
{
    /**
     * user_id 刻意排除在外，與其他 user-owned 模型一致（Watchlist、Holding、
     * Alert、StockAnalysis、LlmProviderSetting）。
     *
     * 目前呼叫點都是關聯 create()，本來就不會塞 user_id；但少了這道護欄，日後
     * 有人寫 NewsAnalysis::create($request->all()) 就能直接指定他人的 user_id。
     */
    protected $fillable = [
        'news_item_id',
        'type',
        'provider_type',
        'model',
        'prompt_version',
        'sentiment',
        'impact_score',
        'related_symbols',
        'summary',
        'reasoning',
        'raw_output',
        'data_as_of',
    ];

    protected function casts(): array
    {
        return [
            'related_symbols' => 'array',
            'raw_output' => 'array',
            'data_as_of' => 'datetime',
            'impact_score' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}
