<?php

namespace App\Models;

use App\Enums\AssetType;
use App\Enums\MarketRegion;
use App\Services\News\SymbolDictionary;
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

    /**
     * 新聞的公司名對照表由 config ∪ instruments 組成並快取（見 SymbolDictionary）。
     *
     * 使用者搜尋一檔新股票就會建立 instrument，但快取仍是舊的對照表，該檔在
     * 快取到期前都不會被新聞辨識出來。名稱變更（例如 provider 先以 symbol 當
     * 名稱建立、之後補上正式名稱）同理。兩種情況都要讓字典失效。
     */
    protected static function booted(): void
    {
        static::created(fn () => app(SymbolDictionary::class)->forget());

        static::updated(function (self $instrument): void {
            if ($instrument->wasChanged(['symbol', 'name'])) {
                app(SymbolDictionary::class)->forget();
            }
        });

        static::deleted(fn () => app(SymbolDictionary::class)->forget());
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
