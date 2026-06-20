<?php

namespace App\Models;

use App\Enums\AssetType;
use App\Enums\MarketRegion;
use Database\Factories\InstrumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instrument extends Model
{
    /** @use HasFactory<InstrumentFactory> */
    use HasFactory;

    protected $fillable = [
        'symbol',
        'name',
        'market',
        'asset_type',
        'currency',
        'exchange',
    ];

    protected function casts(): array
    {
        return [
            'market' => MarketRegion::class,
            'asset_type' => AssetType::class,
        ];
    }

    public function dailyPrices(): HasMany
    {
        return $this->hasMany(DailyPrice::class);
    }

    public function technicalSnapshots(): HasMany
    {
        return $this->hasMany(TechnicalSnapshot::class);
    }

    public function stockAnalyses(): HasMany
    {
        return $this->hasMany(StockAnalysis::class);
    }

    public function watchlistItems(): HasMany
    {
        return $this->hasMany(WatchlistItem::class);
    }
}
