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
        'published_at',
        'language',
        'topic',
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
