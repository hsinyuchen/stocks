<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NewsItem extends Model
{
    protected $fillable = [
        'source',
        'title',
        'summary',
        'url',
        'url_hash',
        'published_at',
        'language',
        'market',
        'topic',
        'domain',
        'domains',
        'relevant',
        'kind',
        'related_symbols',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'related_symbols' => 'array',
            'domains' => 'array',
            'relevant' => 'boolean',
        ];
    }

    /** 排除與投資無關的雜訊（美食、社會案件、生活理財）。 */
    public function scopeRelevant(Builder $query): Builder
    {
        return $query->where('relevant', true);
    }
}
