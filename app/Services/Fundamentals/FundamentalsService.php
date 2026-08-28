<?php

namespace App\Services\Fundamentals;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FundamentalsProvider;
use App\Data\FundamentalsData;
use App\Data\OrderInventoryData;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Support\DailyDataFreshness;
use App\Support\MarketResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class FundamentalsService
{
    public function __construct(
        private readonly FundamentalsProvider $provider,
        private readonly CompanyFinancialsProvider $financials,
    ) {}

    /**
     * 台股基本面（快取優先）。非台股回 null。best-effort：抓失敗不擋頁面。
     */
    public function forInstrument(Instrument $instrument): ?FundamentalsData
    {
        if (! MarketResolver::isTaiwan($instrument->symbol)) {
            return null;
        }

        $row = $this->latestRow($instrument);

        if ($row !== null && ! $this->isStale($row)) {
            return $this->toData($row);
        }

        // 抓取失敗判定：拋例外，或未拋但回全 null DTO（FinMind rate-limit/5xx →
        // provider->rows() 回 []，fetch() 靜默回全 null）。兩者一律視為失敗，
        // 不得覆蓋既有非空列（保留 last-known-good）。
        try {
            $data = $this->provider->fetch($instrument->symbol);
        } catch (\Throwable $exception) {
            Log::warning('fundamentals: fetch failed', ['symbol' => $instrument->symbol, 'error' => $exception->getMessage()]);

            return $this->handleFailure($instrument, $row);
        }

        if (! $this->hasAnyMetric($data)) {
            return $this->handleFailure($instrument, $row);
        }

        // 序列與估值共用同一次「是否過期」判斷與同一列快取，避免兩套 TTL 各自漂移。
        // 台股兩者是同一個 FinMind provider 實例（容器 singleton）、共用同一份 rows
        // memo 與同一個回溯起始日，故重複的三個 dataset 整體只打一次 FinMind。
        $orderInventory = $this->financials->financials(
            $instrument->symbol,
            (int) config('order_inventory.history_months', 30),
        );

        $data = new FundamentalsData(
            per: $data->per,
            pbr: $data->pbr,
            dividendYield: $data->dividendYield,
            eps: $data->eps,
            epsQuarter: $data->epsQuarter,
            roe: $data->roe,
            revenue: $data->revenue,
            revenueMonth: $data->revenueMonth,
            revenueYoy: $data->revenueYoy,
            dataAsOf: $data->dataAsOf,
            orderInventory: $this->carryForwardOrderInventory($orderInventory, $row),
        );

        // 成功抓取：寫入指標、刷新 fetched_at、清除 failed_at（persist 預設 failedAt=null）。
        $this->persist($instrument, $data);

        return $data;
    }

    /**
     * 只讀的估值快照：最新一列的指標、它是**什麼時候抓的**，以及那個時間點
     * 在估值自己的新鮮度尺上算不算過期。
     *
     * 與 forInstrument() 的差別只有一件事——**不抓、一次上游都不打**。
     * 為體質判讀的 cachedFor() 入口而存在，理由與 cachedOrderInventoryFor() 相同：
     * 那條路徑跑在同步 web 請求裡，而 PHP 的 max_execution_time 不是例外、
     * 呼叫端的 try/catch 攔不到。
     *
     * fetched_at 與 FundamentalsData 一起回，而不是讓呼叫端自己再查一次列：
     * 兩次查詢可能挑到不同的列（同一檔有多列歷史），那時呈現層說出來的
     * 「資料日期」就不屬於它旁邊那組數字。
     *
     * **`stale` 由這裡算，不交給呼叫端**，兩個理由：
     *
     * 1. 判準是估值路徑自己的（{@see DailyDataFreshness::isStale()}「今天盤後的
     *    公佈了沒」，與 {@see isStale()} 同一條）。散到消費端就會出現第二套尺。
     * 2. `fetched_at` 對外只給日期字串，而這條尺比的是**時刻**：一列今天 20:00
     *    抓的資料，只留日期就會在 15:00 的公佈時刻前被判成過期。時刻不出這個
     *    方法，答案才不會因為精度流失而反轉。
     *
     * 這裡刻意**不走 isStale()** 本身：那個方法答的是「該不該重抓」，含
     * failure_ttl 的重試節流——一列一小時前剛抓失敗的資料會被算成「不必重抓」，
     * 但它的內容照樣是三個月前的。消費端要問的是資料多舊，不是要不要重試。
     *
     * @return array{data: FundamentalsData, fetched_at: ?string, stale: bool}|null
     */
    public function cachedValuationFor(Instrument $instrument): ?array
    {
        $row = $this->latestRow($instrument);

        if ($row === null) {
            return null;
        }

        return [
            'data' => $this->toData($row),
            'fetched_at' => $row->fetched_at?->toDateString(),
            'stale' => DailyDataFreshness::isStale(
                $row->fetched_at,
                (int) config('fundamentals.publish_hour', 15),
            ),
        ];
    }

    /**
     * 訂單／庫存判斷用的財報序列，兩個市場都走這個入口。
     *
     * 與 forInstrument() 分家而不是擴充它：已查證 5 個消費端都以
     * forInstrument() 回 null 當作「這個市場沒有基本面」的旗標，若它開始為美股
     * 回傳估值全 null 的 DTO，前端面板會渲染一整排 null、兩支 LLM prompt 會多出
     * 整塊 null 的 fundamentals、Screener 的 NEEDS_FUNDAMENTALS 規則會從「跳過該檔」
     * 變成「拿全 null 去比較」。序列的消費端與估值的消費端不同，入口就該分開。
     */
    public function orderInventoryFor(Instrument $instrument): ?OrderInventoryData
    {
        return MarketResolver::isTaiwan($instrument->symbol)
            ? $this->taiwanOrderInventory($instrument)
            : $this->unitedStatesOrderInventory($instrument);
    }

    /**
     * 序列的**只讀**入口：已經在 DB 且未過期才回傳，否則回 null，一次上游都不打。
     *
     * 給跑在同步 web 請求裡的消費端用（目前是首頁的警報評估）。orderInventoryFor()
     * 過期時會就地抓一次上游，美股那條打的是 SEC EDGAR、timeout 40 秒，且沒有
     * FinMindGate 那種斷路器；受限主機的 max_execution_time（常見 30 秒）會先把整個
     * 請求砍掉，而 PHP 的執行時間上限不是例外，呼叫端的 try/catch 攔不到。
     * 這個入口讓「拿不到就當沒有」變成可選的語意，不必為此犧牲其他呼叫端的即時抓取。
     *
     * 新鮮度判準與各自市場的正常路徑完全一致（台股 isStale()、美股
     * isUnitedStatesStale()），確保「這裡回 null」等價於「正常路徑此刻會去抓上游」，
     * 兩套判準不會漂移。
     *
     * 取列走 latestSeriesRow() 而**不是** latestRow()：美股失敗且無既有列時寫的
     * 負快取列 data_as_of 是「今天」，成功列則是「季末日」，後者永遠比較早，所以
     * 只要該檔第一次抓取失敗過，latestRow() 就永遠回那一列不帶序列的負快取列，
     * 這個入口會恆回 null——該標的的訂單庫存警報從此永久靜默不觸發，而序列其實
     * 已經落地且新鮮。
     */
    public function cachedOrderInventoryFor(Instrument $instrument): ?OrderInventoryData
    {
        $row = $this->latestSeriesRow($instrument);

        if ($row === null) {
            return null;
        }

        $stale = MarketResolver::isTaiwan($instrument->symbol)
            ? $this->isStale($row)
            : $this->isUnitedStatesStale($row);

        return $stale ? null : OrderInventoryData::fromArray($row->order_inventory);
    }

    /**
     * 序列的只讀入口，新鮮度用**序列自己的視窗**（order_inventory.series_freshness_days）。
     *
     * **為什麼不共用 cachedOrderInventoryFor()**：那個方法的新鮮度刻意與各自市場的
     * 抓取路徑一致（台股 isStale()、美股 isUnitedStatesStale()），因為它服務的是
     * **評級**——「這裡回 null」必須等價於「正常路徑此刻會去抓上游」，訂單庫存規則
     * 依賴這個等價關係。但台股那把尺（isStale()）問的是「今天盤後的**估值**公佈了
     * 沒」，為每日更新的 PER／PBR／EPS 設計；order_inventory 是季財報＋月營收，
     * 季報約在季末後 45 天送件、月營收每月 10 日前公告，一天不會有新東西。
     * 拿每日 TTL 去量季／月序列，會讓昨天抓的整條序列在今天 15:00 之後被判「沒有」。
     *
     * 這對評級沒差（規則本來就接受「拿不到就不命中」），但對只讀快取又**不做**
     * 抓取的消費端是致命的：社交套利的營收與毛利兩條腿因此在選股器與首頁警報上
     * 恆為 null，而個股頁只是因為呼叫順序先暖了快取才看得到——同一檔標的於是在
     * 兩個畫面上得到不同的分類。這個入口存在就是為了解開那個順序依賴。
     *
     * 取列走 latestSeriesRow()，理由與 cachedOrderInventoryFor() 相同（見該方法）。
     * 兩個市場共用同一個視窗：季報的公告節奏台美一致，不需要再分岔一次。
     */
    public function orderInventorySeriesFor(Instrument $instrument): ?OrderInventoryData
    {
        $row = $this->latestSeriesRow($instrument);

        if ($row === null || $row->fetched_at === null) {
            return null;
        }

        // startOfDay 綁定：不切齊的話門檻會帶當下的時分秒，同一列在午夜前後一下
        // 納入一下排除，而且視窗實際只有 N-1 天多（同
        // OrderInventoryIndustrySampler::freshnessFloor() 的理由）。
        $floor = CarbonImmutable::now()->subDays($this->seriesFreshnessDays())->startOfDay();

        return $row->fetched_at->greaterThanOrEqualTo($floor)
            ? OrderInventoryData::fromArray($row->order_inventory)
            : null;
    }

    /**
     * 缺鍵或非正整數一律拋錯，不做裸 `(int)` 轉型（`(int) null === 0`）：視窗變 0
     * 會把新鮮度視窗縮到今天，序列於是恆為不可用，而不會有任何錯誤訊號可供察覺
     * ——那正是本入口要修掉的那個失敗模式。
     */
    private function seriesFreshnessDays(): int
    {
        $value = config('order_inventory.series_freshness_days');

        if (! is_numeric($value) || (int) $value < 1) {
            throw new \RuntimeException('order_inventory.series_freshness_days config 缺失或非正整數，無法判斷序列新鮮度。');
        }

        return (int) $value;
    }

    /**
     * 台股：序列本來就由 forInstrument() 連同估值一次抓、寫進同一列。
     *
     * 這裡只做「讀既有列」，過期時委派回 forInstrument()，不自己開第二條抓取
     * 路徑——兩條路徑等於兩套 TTL，會各自漂移，且 FinMind 免費層的額度會被
     * 同一份資料抓兩次白白吃掉。
     */
    private function taiwanOrderInventory(Instrument $instrument): ?OrderInventoryData
    {
        $row = $this->latestRow($instrument);

        if ($row !== null && is_array($row->order_inventory) && ! $this->isStale($row)) {
            return OrderInventoryData::fromArray($row->order_inventory);
        }

        return $this->forInstrument($instrument)?->orderInventory;
    }

    /**
     * 美股：自己的一條讀取／新鮮度／抓取／落地路徑（估值欄位一概不寫）。
     *
     * best-effort：例外一律吞掉並走失敗路徑，不往外拋擋頁面。
     */
    private function unitedStatesOrderInventory(Instrument $instrument): ?OrderInventoryData
    {
        // 帶序列的最新列若還新鮮就直接回，不看 latestRow()。
        //
        // 這一步不能省：負快取列的 data_as_of 是「今天」、成功列是「季末日」，
        // 後者永遠比較早，所以該檔第一次抓取失敗之後，latestRow() 恆為那一列
        // 不帶序列的負快取列、恆判定過期，快取形同不存在，每次開頁都重打 SEC。
        // 只在「有新鮮序列」時短路，其餘情況一律走原本以 latestRow() 為準的流程
        // ——失敗節流與 last-known-good 都依賴看得到負快取列本身。
        $series = $this->latestSeriesRow($instrument);

        if ($series !== null && ! $this->isUnitedStatesStale($series)) {
            return OrderInventoryData::fromArray($series->order_inventory);
        }

        $row = $this->latestRow($instrument);
        $previous = is_array($row?->order_inventory)
            ? OrderInventoryData::fromArray($row->order_inventory)
            : null;

        if ($row !== null && ! $this->isUnitedStatesStale($row)) {
            return $previous;
        }

        try {
            $fresh = $this->financials->financials(
                $instrument->symbol,
                (int) config('order_inventory.history_months', 30),
            );
        } catch (\Throwable $exception) {
            Log::warning('order inventory: us fetch failed', [
                'symbol' => $instrument->symbol,
                'error' => $exception->getMessage(),
            ]);

            $fresh = OrderInventoryData::empty();
        }

        // 失敗與部分失敗一律沿用舊值，與估值那條路的 last-known-good 同一條規則。
        $merged = $this->carryForwardOrderInventory($fresh, $row);

        if (! $fresh->hasAny()) {
            if ($row !== null) {
                // 既有列：保留原本的序列、data_as_of 與 fetched_at，只刷新 failed_at
                // 節流重試。另開一列會讓放棄的抓取在序列裡留下一筆空資料。
                $row->forceFill(['failed_at' => now()])->save();

                return $merged;
            }

            // 沒有既有列：寫一列純節流用的負快取。它不帶序列，故仍可被清理。
            $this->persistOrderInventory($instrument, null, null, now());

            return null;
        }

        $this->persistOrderInventory($instrument, $merged, $fresh->dataAsOf, null);

        return $merged;
    }

    /**
     * 美股新鮮度。
     *
     * 不能沿用 isStale()：美股列的估值欄位天生全 null，hasMetric() 一律 false，
     * isStale() 會把每一列都當成「負快取列」用 failure_ttl 節流，導致對 SEC
     * 過度重抓。改用 order_inventory.us_ttl_hours（季度財報，日級即可）。
     */
    private function isUnitedStatesStale(Fundamental $row): bool
    {
        $ttl = (int) config('order_inventory.us_ttl_hours', 24);

        if (is_array($row->order_inventory)
            && $row->fetched_at !== null
            && $row->fetched_at->greaterThan(CarbonImmutable::now()->subHours($ttl))) {
            return false;
        }

        // 失敗節流沿用估值那套 failure_ttl：SEC 故障或被限流時不該每次開頁重打。
        return $row->failed_at === null
            || $row->failed_at->lessThan(CarbonImmutable::now()->subHours($this->failureTtl()));
    }

    /**
     * 只寫序列相關欄位，估值欄位一律不碰（美股沒有估值來源，寫 null 會把台股的
     * 保護邏輯搞混）。
     *
     * data_as_of 同欄不同語意：台股是 PER 日期（每日），美股是最新季末日（每季）。
     * 兩個市場的列不會落在同一個 instrument 上，所以不衝突；但任何跨市場直接
     * 比較 data_as_of 的程式碼都是錯的。
     */
    private function persistOrderInventory(
        Instrument $instrument,
        ?OrderInventoryData $data,
        ?string $dataAsOf,
        ?\DateTimeInterface $failedAt,
    ): void {
        // 必須傳 Carbon 而非 'Y-m-d' 字串：date cast 寫入時會展開成
        // 'Y-m-d H:i:s'，用字串查詢比不中既有列，會撞上唯一鍵而拋例外。
        $key = CarbonImmutable::parse($dataAsOf ?? now()->toDateString())->startOfDay();

        Fundamental::query()->updateOrCreate(
            [
                'instrument_id' => $instrument->id,
                'data_as_of' => $key,
            ],
            [
                'data_as_of' => $key,
                'fetched_at' => now(),
                'failed_at' => $failedAt,
                'order_inventory' => $data?->toArray(),
            ],
        );
    }

    /** 改為保留歷史後同一檔會有多列，新鮮度一律看最新那筆。 */
    private function latestRow(Instrument $instrument): ?Fundamental
    {
        return Fundamental::query()
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('data_as_of')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 帶著序列的最新一列（`order_inventory` 非 null）。
     *
     * 與 latestRow() 刻意不同：那一個服務估值路徑，**必須**看得到不帶序列的負快取列
     * ——failed_at 的重試節流、handleFailure() 的 last-known-good 判定、以及美股
     * 失敗時的節流窗口都以它為依據，換成本方法會讓 SEC 故障時每次請求都重打。
     * 判準與 OrderInventoryAssessor::targetRow() 同一套（「order_inventory 非 null」
     * 才是序列真正落地的可靠判準），兩處若要調整必須一起看。
     */
    private function latestSeriesRow(Instrument $instrument): ?Fundamental
    {
        return Fundamental::query()
            ->where('instrument_id', $instrument->id)
            ->whereNotNull('order_inventory')
            ->orderByDesc('data_as_of')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 序列取不到時沿用既有值，不寫 null 覆蓋。
     *
     * 抓不到不等於沒有：序列與估值共用同一次抓取，但 FinMind 免費層額度常在估值
     * 抓完之後才撞上（PER 已到手 → hasAnyMetric() 為 true → 不走 handleFailure），
     * 此時 financials() 的每個 dataset 都被 FinMindGate 短路回空。persist() 的鍵是
     * (instrument_id, data_as_of)，而 data_as_of 是 PER 日期、公佈前一整天不變，
     * 覆蓋會就地毀掉已落地的觀測值且無從復原。估值欄位有 handleFailure() 的
     * last-known-good 保護，這個 JSON 欄位沒有，故在此補上對稱的保護。
     */
    private function carryForwardOrderInventory(OrderInventoryData $fresh, ?Fundamental $row): ?OrderInventoryData
    {
        $previous = is_array($row?->order_inventory)
            ? OrderInventoryData::fromArray($row->order_inventory)
            : null;

        // hasAny() 只看季度。這次若抓到了月營收，即使季報缺席也要用新的——
        // 否則剛上市或財報延遲的個股，月營收永遠停在第一次抓到的那個月。
        if (! $fresh->hasAny() && ! $fresh->hasRevenueSeries()) {
            return $previous;
        }

        if ($previous === null) {
            return $fresh;
        }

        // 季度與月營收來自不同 FinMind dataset，同一次請求裡任一個可能單獨撞額度
        // 或故障而回空陣列，另一個仍可能正常抓到新資料，故分開判斷、各自決定是否
        // 沿用舊值：
        // - 季度序列是訂單庫存評級唯一來源，被 [] 蓋掉會讓評級從有結論靜默變棄權。
        // - 月營收序列是階段 2 判斷 YoY 連續性唯一來源，理由同上、不可被 [] 覆蓋。
        $quarters = $fresh->quarters === [] && $previous->quarters !== []
            ? $previous->quarters
            : $fresh->quarters;

        $monthlyRevenue = $fresh->monthlyRevenue === [] && $previous->monthlyRevenue !== []
            ? $previous->monthlyRevenue
            : $fresh->monthlyRevenue;

        if ($quarters === $fresh->quarters && $monthlyRevenue === $fresh->monthlyRevenue) {
            return $fresh;
        }

        return new OrderInventoryData(
            quarters: $quarters,
            monthlyRevenue: $monthlyRevenue,
            market: $fresh->market,
            industry: $fresh->industry,
            inventoryCompositionAvailable: $fresh->inventoryCompositionAvailable,
            dataAsOf: $fresh->dataAsOf,
        );
    }

    /**
     * 估值分位：目前 PER / PBR 落在該檔自身歷史的哪個位置。
     *
     * 只用本檔自己的歷史比較，不跨股比較——不同產業的合理本益比差距太大，
     * 跨股分位沒有解讀價值。樣本不足時回 null，不輸出看似精確的假分位。
     *
     * @return array<string, array{value: float, percentile: float, min: float, median: float, max: float, samples: int}>|null
     */
    public function valuationPercentiles(Instrument $instrument): ?array
    {
        $minSamples = (int) config('fundamentals.percentile_min_samples', 20);

        $rows = Fundamental::query()
            ->where('instrument_id', $instrument->id)
            ->orderBy('data_as_of')
            ->get(['per', 'pbr', 'data_as_of']);

        $out = [];

        foreach (['per', 'pbr'] as $metric) {
            $values = $rows->pluck($metric)
                ->filter(fn ($v): bool => $v !== null && (float) $v > 0)
                ->map(fn ($v): float => (float) $v)
                ->values();

            if ($values->count() < $minSamples) {
                continue;
            }

            $current = (float) $values->last();
            $sorted = $values->sort()->values()->all();
            $count = count($sorted);
            // 低於或等於現值的樣本比例：0 = 歷史最低（最便宜），100 = 歷史最高。
            $below = count(array_filter($sorted, static fn (float $v): bool => $v <= $current));

            $out[$metric] = [
                'value' => round($current, 2),
                'percentile' => round($below / $count * 100, 1),
                'min' => round($sorted[0], 2),
                'median' => round($count % 2 === 1
                    ? $sorted[intdiv($count, 2)]
                    : ($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2, 2),
                'max' => round($sorted[$count - 1], 2),
                'samples' => $count,
            ];
        }

        return $out === [] ? null : $out;
    }

    /**
     * 抓取失敗時：
     * - 既有非空列：保留 last-known-good 指標與原 fetched_at，只刷新 failed_at 節流重試，回傳既有資料。
     * - 無既有非空列：寫全 null 負快取列（failure_ttl 節流），回傳 null。
     */
    private function handleFailure(Instrument $instrument, ?Fundamental $row): ?FundamentalsData
    {
        if ($row !== null && $this->hasMetric($row)) {
            $row->forceFill(['failed_at' => now()])->save();

            return $this->toData($row);
        }

        // 負快取列只是重試節流，不是歷史觀測值。改為保留歷史後，若不先清掉
        // 舊的全 null 列，抓不到的標的每天都會多一列空資料，污染分位統計。
        //
        // 帶著 order_inventory 的列必須排除：它是觀測值，不是重試節流的殘留。
        // 「估值欄位全 null」這個條件本身分不出兩者——美股列每一列都符合，
        // 目前只是靠「handleFailure() 只在台股路徑被呼叫、且以 instrument_id
        // 限縮」這個呼叫端的巧合在保護，條件本身必須自己站得住。
        $stale = Fundamental::query()
            ->where('instrument_id', $instrument->id)
            ->whereNull('order_inventory');

        foreach (Fundamental::METRIC_COLUMNS as $col) {
            $stale->whereNull($col);
        }

        $stale->delete();

        $this->persist($instrument, new FundamentalsData);

        return null;
    }

    /**
     * 新鮮度：
     * - 有指標列：failed_at 在 failure_ttl 內視為 fresh（節流失敗重試）；否則問「今天的盤後
     *   資料公佈了沒」（DailyDataFreshness），而非固定小時 TTL。
     * - 全 null 列（無既有非空資料）：以 failure_ttl 節流 fetched_at。
     */
    private function isStale(Fundamental $row): bool
    {
        if (! $this->hasMetric($row)) {
            return $row->fetched_at === null
                || $row->fetched_at->lessThan(CarbonImmutable::now()->subHours($this->failureTtl()));
        }

        if ($row->failed_at !== null
            && $row->failed_at->greaterThan(CarbonImmutable::now()->subHours($this->failureTtl()))) {
            return false;
        }

        // 估值每日盤後公佈，用固定小時數判斷會讓過期時刻逐日漂移，
        // 使用者在公佈後到過期前只看得到前一日的數字。改問「今天的公佈了沒」。
        return DailyDataFreshness::isStale(
            $row->fetched_at,
            (int) config('fundamentals.publish_hour', 15),
        );
    }

    private function failureTtl(): int
    {
        return (int) config('fundamentals.failure_ttl_hours', 2);
    }

    private function persist(Instrument $instrument, FundamentalsData $data, ?\DateTimeInterface $failedAt = null): void
    {
        // 唯一鍵改為 (instrument_id, data_as_of)：同一資料日重抓仍是就地更新，
        // 跨日則新增一列，累積成可算分位的序列。data_as_of 缺漏時以今天代替，
        // 否則所有缺值的抓取都會擠在同一列互相覆蓋。
        //
        // 必須傳 Carbon 而非 'Y-m-d' 字串：date cast 寫入時會展開成
        // 'Y-m-d H:i:s'，用字串查詢比不中既有列，會撞上唯一鍵而拋例外。
        $key = CarbonImmutable::parse($data->dataAsOf ?? now()->toDateString())->startOfDay();

        Fundamental::query()->updateOrCreate(
            [
                'instrument_id' => $instrument->id,
                'data_as_of' => $key,
            ],
            [
                'per' => $data->per, 'pbr' => $data->pbr, 'dividend_yield' => $data->dividendYield,
                'eps' => $data->eps, 'roe' => $data->roe,
                'revenue' => $data->revenue, 'revenue_yoy' => $data->revenueYoy,
                'eps_quarter' => $data->epsQuarter, 'revenue_month' => $data->revenueMonth,
                'data_as_of' => $key,
                'fetched_at' => now(),
                'failed_at' => $failedAt,
                'order_inventory' => $data->orderInventory?->toArray(),
            ],
        );
    }

    private function hasMetric(Fundamental $row): bool
    {
        foreach (Fundamental::METRIC_COLUMNS as $col) {
            if ($row->{$col} !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasAnyMetric(FundamentalsData $data): bool
    {
        return $data->per !== null || $data->pbr !== null || $data->dividendYield !== null
            || $data->eps !== null || $data->roe !== null
            || $data->revenue !== null || $data->revenueYoy !== null;
    }

    private function toData(Fundamental $row): FundamentalsData
    {
        // decimal cast 回傳 string，一律 (float)；date cast 轉 Y-m-d 字串。
        $f = fn ($v): ?float => $v === null ? null : (float) $v;

        return new FundamentalsData(
            per: $f($row->per), pbr: $f($row->pbr), dividendYield: $f($row->dividend_yield),
            eps: $f($row->eps), epsQuarter: $row->eps_quarter?->toDateString(), roe: $f($row->roe),
            revenue: $f($row->revenue), revenueMonth: $row->revenue_month?->toDateString(),
            revenueYoy: $f($row->revenue_yoy), dataAsOf: $row->data_as_of?->toDateString(),
            orderInventory: is_array($row->order_inventory)
                ? OrderInventoryData::fromArray($row->order_inventory)
                : null,
        );
    }
}
