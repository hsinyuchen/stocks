<?php

namespace App\Models;

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
        'related_symbols',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'related_symbols' => 'array',
        ];
    }
}
