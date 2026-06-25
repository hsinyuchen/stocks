<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Enums\AssetType;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Support\MarketResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class CachedMarketDataProvider implements MarketDataProvider
{
    public function __construct(
        private readonly MarketDataProvider $upstream,
        private readonly int $ttlMinutes = 720,
        private readonly int $quoteCacheSeconds = 60,
    ) {}

    public function quote(string $symbol): MarketQuoteData
    {
        return Cache::remember(
            'market:quote:'.strtoupper($symbol),
            $this->quoteCacheSeconds,
            fn (): MarketQuoteData => $this->upstream->quote($symbol),
        );
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        $instrument = $this->resolveInstrument($symbol);

        if ($this->isFresh($instrument)) {
            return $this->readFromDatabase($instrument, $days);
        }

        $fetched = $this->upstream->dailyPrices($symbol, $days);
        $this->store($instrument, $fetched);

        return $this->readFromDatabase($instrument, $days);
    }

    private function resolveInstrument(string $symbol): Instrument
    {
        $symbol = strtoupper(trim($symbol));

        return Instrument::query()->createOrFirst(
            ['symbol' => $symbol],
            [
                'name' => $symbol,
                'market' => MarketResolver::region($symbol),
                'asset_type' => AssetType::Stock,
                'currency' => MarketResolver::currency($symbol),
                'exchange' => null,
            ],
        );
    }

    private function isFresh(Instrument $instrument): bool
    {
        $latest = $instrument->dailyPrices()->latest('updated_at')->first();

        if ($latest === null) {
            return false;
        }

        return $latest->updated_at !== null
            && $latest->updated_at->greaterThan(CarbonImmutable::now()->subMinutes($this->ttlMinutes));
    }

    /** @param list<DailyPriceData> $prices */
    private function store(Instrument $instrument, array $prices): void
    {
        if ($prices === []) {
            throw new RuntimeException("No upstream prices available to cache for {$instrument->symbol}.");
        }

        foreach ($prices as $price) {
            DailyPrice::query()->updateOrCreate(
                ['instrument_id' => $instrument->id, 'priced_at' => $price->date],
                [
                    'open' => $price->open,
                    'high' => $price->high,
                    'low' => $price->low,
                    'close' => $price->close,
                    'volume' => $price->volume,
                ],
            );
        }
    }

    /** @return list<DailyPriceData> */
    private function readFromDatabase(Instrument $instrument, int $days): array
    {
        return $instrument->dailyPrices()
            ->orderByDesc('priced_at')
            ->limit($days)
            ->get()
            ->sortBy('priced_at')
            ->values()
            ->map(fn (DailyPrice $row): DailyPriceData => new DailyPriceData(
                symbol: $instrument->symbol,
                date: $row->priced_at->toDateString(),
                open: (float) $row->open,
                high: (float) $row->high,
                low: (float) $row->low,
                close: (float) $row->close,
                volume: (int) $row->volume,
            ))
            ->all();
    }
}
