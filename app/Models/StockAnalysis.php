<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAnalysis extends Model
{
    protected $fillable = [
        'user_id',
        'instrument_id',
        'technical_snapshot_id',
        'provider_type',
        'model',
        'prompt_version',
        'rule_signal',
        'llm_output',
        'data_as_of',
    ];

    protected function casts(): array
    {
        return [
            'rule_signal' => 'array',
            'llm_output' => 'array',
            'data_as_of' => 'datetime',
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

    public function technicalSnapshot(): BelongsTo
    {
        return $this->belongsTo(TechnicalSnapshot::class);
    }
}
