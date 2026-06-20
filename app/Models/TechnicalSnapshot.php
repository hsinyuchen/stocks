<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalSnapshot extends Model
{
    protected $fillable = [
        'instrument_id',
        'calculated_at',
        'k',
        'd',
        'macd',
        'macd_signal',
        'macd_histogram',
        'ma5',
        'ma20',
        'signals',
    ];

    protected function casts(): array
    {
        return [
            'calculated_at' => 'date',
            'signals' => 'array',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
