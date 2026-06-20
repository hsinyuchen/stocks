<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmProviderSetting extends Model
{
    protected $fillable = [
        'user_id',
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

    protected function casts(): array
    {
        return [
            'is_default' => 'bool',
            'temperature' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
