<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsAnalysis extends Model
{
    protected $fillable = [
        'user_id',
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
