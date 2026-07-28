<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChipFlow extends Model
{
    protected $fillable = [
        'instrument_id',
        'traded_at',
        'foreign_net',
        'trust_net',
        'dealer_net',
        'total_net',
    ];

    protected function casts(): array
    {
        return [
            'traded_at' => 'date:Y-m-d',
            'foreign_net' => 'integer',
            'trust_net' => 'integer',
            'dealer_net' => 'integer',
            'total_net' => 'integer',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
