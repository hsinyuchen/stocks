<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarginFlow extends Model
{
    protected $fillable = [
        'instrument_id',
        'traded_at',
        'margin_balance',
        'margin_change',
        'margin_limit',
        'short_balance',
        'short_change',
        'offset_loan_and_short',
    ];

    protected function casts(): array
    {
        return [
            'traded_at' => 'date:Y-m-d',
            'margin_balance' => 'integer',
            'margin_change' => 'integer',
            'margin_limit' => 'integer',
            'short_balance' => 'integer',
            'short_change' => 'integer',
            'offset_loan_and_short' => 'integer',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
