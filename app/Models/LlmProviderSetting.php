<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmProviderSetting extends Model
{
    protected $fillable = [
        'provider_type',
        'display_name',
        'base_url',
        'api_key_encrypted',
        'model',
        'timeout_seconds',
        'temperature',
        'max_tokens',
        'is_default',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'temperature' => 'decimal:2',
            'max_tokens' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
