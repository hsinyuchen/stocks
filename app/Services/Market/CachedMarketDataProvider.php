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
        // Cache a plain array, not the DTO object: serializing cache stores
        // (database/file/redis) can otherwise deserialize an object back as a
        // __PHP_Incomplete_Class. Rebuild the DTO from primitives on read.
        $data = Cache::remember(
            'market:quote:'.strtoupper($symbol),
            $this->quoteCacheSeconds,
            function () use ($symbol): array {
                $quote = $this->upstream->quote($symbol);

                return [
                    'symbol' => $quote->symbol,
                    'price' => $quote->price,
                    'change' => $quote->change,
                    'changePercent' => $quote->changePercent,
                    'asOf' => $quote->asOf,
                ];
            },
        );

        return new MarketQuoteData(
            symbol: (string) $data['symbol'],
            price: (float) $data['price'],
            change: (float) $data['change'],
            changePercent: (float) $data['changePercent'],
            asOf: (string) $data['asOf'],
        );
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        $instrument = $this->resolveInstrument($symbol);

        if ($this->isFresh($instrument) && $this->covers($instrument, $days)) {
            return $this->readFromDatabase($instrument, $days);
        }

        $fetched = $this->upstream->dailyPrices($symbol, $days);
        $this->store($instrument, $fetched);

        return $this->readFromDatabase($instrument, $days);
    }

    /**
     * 快取涵蓋度：DB 既有 row 數是否足以回應本次請求。
     * 用 row 數而非日期回推，避免假日/停牌造成的日曆日誤差；
     * 上游實際可給的最大歷史可能少於請求（新上市股），因此以
     * 「row 數 >= 請求的 7 成」為足量門檻，避免對天生短歷史的
     * 標的每次都重抓。
     */
    private function covers(Instrument $instrument, int $days): bool
    {
        $rows = $instrument->dailyPrices()->count();

        return $rows >= (int) ceil($days * 0.7);
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
