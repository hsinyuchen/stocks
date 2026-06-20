<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchlistItem extends Model
{
    protected $fillable = [
        'watchlist_id',
        'instrument_id',
        'sort_order',
        'note',
    ];

    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(Watchlist::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
