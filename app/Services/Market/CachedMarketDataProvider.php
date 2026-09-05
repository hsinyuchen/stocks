<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Contracts\TodayBarProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Support\MarketResolver;
use App\Support\OhlcRepair;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class CachedMarketDataProvider implements MarketDataProvider
{
    public function __construct(
        private readonly MarketDataProvider $upstream,
        private readonly int $ttlMinutes = 720,
        private readonly int $quoteCacheSeconds = 60,
        private readonly ?TodayBarProvider $todayBars = null,
        private readonly int $todayBarCacheSeconds = 60,
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
            $prices = $this->readFromDatabase($instrument, $days);
        } else {
            $fetched = $this->upstream->dailyPrices($symbol, $days);
            $this->store($instrument, $fetched);
            $prices = $this->readFromDatabase($instrument, $days);
        }

        return $this->withTodayBar($instrument, $prices, $days);
    }

    /**
     * 把當日 K 棒接在序列尾端。沒有注入 {@see TodayBarProvider}、或那一天取不到時
     * 原樣回傳——降級後只是回到「少最新一根」。
     *
     * **刻意不寫進 `daily_prices`。** 盤中拿到的是未完成棒（high／low／close／
     * volume 都還會變），一旦寫進去就會被 {@see isFresh()} 的 TTL 保護：09:05 存下
     * 的半成品能撐到 21:05，比原本「少一根」更難發現。留在記憶體裡，每次請求重新
     * 取（`todayBarCacheSeconds` 節流），等上游補上官方收盤值後由 {@see store()}
     * 正常寫入，兩邊自然交棒。
     *
     * @param  list<DailyPriceData>  $prices
     * @return list<DailyPriceData>
     */
    private function withTodayBar(Instrument $instrument, array $prices, int $days): array
    {
        $bar = $this->todayBar($instrument->symbol);
        $bar = $bar === null ? null : OhlcRepair::repair($bar);

        if ($bar === null) {
            return $prices;
        }

        // 上游已經補到這一天就不要疊上去：收盤後 DB 裡的才是官方定案值。
        if ($prices !== [] && $bar->date <= $prices[count($prices) - 1]->date) {
            return $prices;
        }

        $prices[] = $bar;

        return array_slice($prices, -$days);
    }

    /**
     * 當日 K 棒，帶短快取。
     *
     * 取不到時同樣寫入快取（存空陣列）：一頁常會對同一檔連續要好幾次序列，
     * 沒有 negative cache 的話每一次都會重打上游。
     */
    private function todayBar(string $symbol): ?DailyPriceData
    {
        if ($this->todayBars === null) {
            return null;
        }

        $symbol = strtoupper(trim($symbol));

        // 與 quote() 同樣的理由存純陣列而非 DTO：序列化型 cache store 讀回
        // 物件會變成 __PHP_Incomplete_Class。
        $data = Cache::remember(
            'market:today-bar:'.$symbol,
            $this->todayBarCacheSeconds,
            function () use ($symbol): array {
                $bar = $this->todayBars->todayBars([$symbol])[$symbol] ?? null;

                return $bar === null ? [] : [
                    'date' => $bar->date,
                    'open' => $bar->open,
                    'high' => $bar->high,
                    'low' => $bar->low,
                    'close' => $bar->close,
                    'volume' => $bar->volume,
                ];
            },
        );

        if ($data === []) {
            return null;
        }

        return new DailyPriceData(
            symbol: $symbol,
            date: (string) $data['date'],
            open: (float) $data['open'],
            high: (float) $data['high'],
            low: (float) $data['low'],
            close: (float) $data['close'],
            volume: (int) $data['volume'],
        );
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

    /** 資料還沒涵蓋到今天時，兩次重抓之間至少要隔這麼久。 */
    private const COVERAGE_RETRY_MINUTES = 60;

    /**
     * 落後幾天以內才值得為了涵蓋度重抓。
     *
     * 這個上界要擋掉的是「上游本身就沒有更新的資料」——停牌、下市、或該標的的
     * 歷史只到某天為止。那種情況每小時重抓一次純粹浪費額度，抓回來還是同一份。
     * 真正要救的情境（收盤了但日線資料源還沒補）缺口只有一到數個交易日，
     * 取 10 天是為了跨得過春節這種最長的連續休市。
     */
    private const COVERAGE_MAX_LAG_DAYS = 10;

    private function isFresh(Instrument $instrument): bool
    {
        $latest = $instrument->dailyPrices()->latest('updated_at')->first();

        if ($latest === null || $latest->updated_at === null) {
            return false;
        }

        if (! $latest->updated_at->greaterThan(CarbonImmutable::now()->subMinutes($this->ttlMinutes))) {
            return false;
        }

        return ! $this->claimCoverageRetry($instrument);
    }

    /**
     * 快取雖然還在 TTL 內，但要不要因為「資料沒涵蓋到今天」而破例重抓一次。
     *
     * TTL 只看**寫入時間**，不看**資料涵蓋到哪一天**，這是「收盤了卻拿不到當日
     * K 棒」的主因：實測 2330 的快取在 2026-09-02 02:54 寫下（那時 FinMind 只有
     * 到 09-01），12 小時的 TTL 於是保護它到 14:54——期間就算上游早已補上 09-02，
     * 重整幾次都沒有用。
     *
     * 必須節流，否則資料一落後就變成「每個請求都重打上游」，而 FinMind 免費層
     * 有每小時額度（見 `FinMindGate`）。命名用 claim 是因為
     * 它會**消耗**這個間隔內唯一的一次機會，呼叫一次就少一次。
     *
     * 週末與國定假日同樣會每 COVERAGE_RETRY_MINUTES 重抓一次卻抓不到新東西。
     * 要免掉得維護一份交易日曆（還有颱風假），代價高於每天多幾次請求。
     *
     * 日期用 UTC 比較（`app.timezone` 為 UTC）：台股 09:00–13:30、美股日盤換算
     * UTC 都落在同一天，刻意不引進交易所時區處理——與
     * {@see YahooChartMarketDataProvider} 的日期慣例一致。
     */
    private function claimCoverageRetry(Instrument $instrument): bool
    {
        $newest = $instrument->dailyPrices()->max('priced_at');

        if ($newest === null) {
            return false;
        }

        $newestDate = CarbonImmutable::parse($newest)->startOfDay();
        $today = CarbonImmutable::now()->startOfDay();

        if ($newestDate >= $today) {
            return false;
        }

        // 週六日台美都不開盤，資料停在週五是常態不是落後；這兩天重抓只會每小時
        // 拉一次七年份的日線回來比對。國定假日仍會漏過去，見上方說明。
        if ($today->isWeekend()) {
            return false;
        }

        if ($newestDate->diffInDays($today) > self::COVERAGE_MAX_LAG_DAYS) {
            return false;
        }

        $key = 'market:coverage-retry:'.$instrument->id;

        if (Cache::has($key)) {
            return false;
        }

        Cache::put($key, true, CarbonImmutable::now()->addMinutes(self::COVERAGE_RETRY_MINUTES));

        return true;
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

    /**
     * 讀出時逐根過 {@see OhlcRepair}——這是所有消費端共用的唯一出口，表內已經存下
     * 的壞列（正式機 6546 有 113 筆在視窗內）在這裡修，不需要跑資料修正。
     * 寫入端刻意保留上游原值，之後要追查來源還看得到。
     *
     * @return list<DailyPriceData>
     */
    private function readFromDatabase(Instrument $instrument, int $days): array
    {
        $bars = $instrument->dailyPrices()
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

        return OhlcRepair::repairAll($bars);
    }
}
