<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPrice extends Model
{
    protected $fillable = [
        'instrument_id',
        'priced_at',
        'open',
        'high',
        'low',
        'close',
        'volume',
    ];

    protected function casts(): array
    {
        return [
            'priced_at' => 'date',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
