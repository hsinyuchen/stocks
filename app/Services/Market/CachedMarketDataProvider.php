<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
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
                'asset_type' => MarketResolver::assetType($symbol),
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

    /**
     * 上游重定基準的判定門檻（相對誤差）。
     *
     * 拆股會讓整段歷史等比例改變（最小的 2:1 即 50%），遠高於此門檻；
     * decimal(18,4) 的捨入誤差則遠低於此。門檻取 2% 以容忍上游對個別
     * K 棒的事後修正，同時仍能攔下任何等比例的基準變動。
     */
    private const REBASE_TOLERANCE = 0.02;

    /** 少於此數量的重疊日期無從分辨整段重定基準與單根修正。 */
    private const REBASE_MIN_SAMPLES = 3;

    /** 個別比值與中位數的容許偏差；拆股後所有比值會幾乎相同。 */
    private const REBASE_RATIO_AGREEMENT = 0.01;

    /** 需有多少比例的重疊日期呈現同一比值，才認定為整段重定基準。 */
    private const REBASE_AGREEMENT_SHARE = 0.8;

    /** @param list<DailyPriceData> $prices */
    private function store(Instrument $instrument, array $prices): void
    {
        if ($prices === []) {
            throw new RuntimeException("No upstream prices available to cache for {$instrument->symbol}.");
        }

        // 上游（例如 Yahoo）在拆股後會回溯重算整段歷史。本方法只寫入本次請求
        // 的視窗，若表內既有 row 多於視窗，舊 row 會停留在舊基準，序列在交界
        // 處出現等比例假跳空，並汙染 MA/KD/MACD。且因 covers() 以總 row 數判斷
        // 涵蓋度，汙染不會自行修復。偵測到基準改變就整檔清空重寫。
        if ($this->upstreamRebased($instrument, $prices)) {
            $instrument->dailyPrices()->delete();
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

    /**
     * 同一交易日的收盤價，上游新值與表內舊值是否已不在同一基準上。
     *
     * 只比對日期重疊的部分：不重疊代表是新資料，不代表基準改變。
     *
     * @param  list<DailyPriceData>  $prices
     */
    private function upstreamRebased(Instrument $instrument, array $prices): bool
    {
        $incoming = [];

        foreach ($prices as $price) {
            $incoming[$price->date] = $price->close;
        }

        $existing = $instrument->dailyPrices()
            ->whereIn('priced_at', array_keys($incoming))
            ->get(['priced_at', 'close']);

        $ratios = [];

        foreach ($existing as $row) {
            $stored = (float) $row->close;
            $fresh = $incoming[$row->priced_at->toDateString()] ?? null;

            if ($stored <= 0.0 || $fresh === null) {
                continue;
            }

            $ratios[] = $fresh / $stored;
        }

        // 樣本太少無從分辨「整段重定基準」與「單根修正」，寧可不動歷史。
        if (count($ratios) < self::REBASE_MIN_SAMPLES) {
            return false;
        }

        sort($ratios);
        $median = $ratios[intdiv(count($ratios), 2)];

        if (abs($median - 1.0) <= self::REBASE_TOLERANCE) {
            return false;
        }

        // 關鍵判別：拆股會讓「所有」重疊日期以同一比例改變，單根資料修正只會
        // 產生一個離群比值。若不檢查一致性，今日 K 棒由盤中值更新為收盤值
        // （日內波動 2% 以上很常見）就會被誤判為拆股，把整段歷史刪光——
        // 實測 250 根會被清成當次請求的 20 根，比原本要修的問題更嚴重。
        $agreeing = 0;

        foreach ($ratios as $ratio) {
            if (abs($ratio / $median - 1.0) <= self::REBASE_RATIO_AGREEMENT) {
                $agreeing++;
            }
        }

        return $agreeing >= (int) ceil(count($ratios) * self::REBASE_AGREEMENT_SHARE);
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
