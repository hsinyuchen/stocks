<?php

namespace App\Services\Health;

use App\Contracts\MarketDataProvider;
use App\Data\ChipFlowData;
use App\Data\DailyPriceData;
use App\Data\HealthInputSnapshot;
use App\Enums\MarketRegion;
use App\Models\ChipFlow;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Services\Chip\ChipDataService;
use App\Services\Fundamentals\FundamentalsService;
use App\Services\Fundamentals\OrderInventoryAssessor;
use App\Services\TechnicalIndicatorService;
use App\Support\MarketResolver;

/**
 * 判讀的**唯一** IO 邊界：把四條輸入（行情、籌碼、估值、財報序列）取齊，
 * 組成一份不可變的 {@see HealthInputSnapshot} 交給兩個純計算 reader。
 *
 * 與階段 2 的 {@see OrderInventoryAssessor}、階段 4 的
 * SocialArbitrageAssessor 同一模式——所有需要資料庫
 * 或網路的事都只發生在這裡。
 *
 * **兩個入口的語意不同，不是同一件事的兩個名字：**
 *
 * - `cachedFor()`：只讀已快取資料，**一次上游都不打**。給同步 web 請求用
 *   （個股頁、首頁、選股掃描）。那條路徑沒有選股掃描的 scan_time_budget_seconds
 *   或快報 job 的 timeout 那種總量預算，而 PHP 的 `max_execution_time` 不是例外、
 *   `try/catch` 攔不到，只能從「不抓」這一端解。拿不到就當沒有：對應的塊回
 *   不可評估，等下一次個股分析把資料抓進快取即可。
 * - `freshFor()`：允許刷新，給個股分析 job 用（它有自己的 timeout）。
 *
 * `cachedFor()` 的價格與籌碼**直接讀 model**，不走既有的兩個服務——這一點無聲
 * 但關鍵，換回去功能照樣正確，只有零上游那條測試會紅：
 *
 * - {@see MarketDataProvider::dailyPrices()}：外層的 `CachedMarketDataProvider`
 *   在「不新鮮」或「涵蓋度不足」時會就地抓上游並寫 DB。
 * - {@see ChipDataService::forInstrument()}：台股路徑在 `isFresh()` 為 false 時
 *   呼叫 provider 打 FinMind。
 *
 * 財報兩條腿走 {@see OrderInventoryAssessor::seriesSignalsFor()} 與
 * {@see FundamentalsService::cachedValuationFor()}，兩者都只讀、也都不寫回評級。
 *
 * **記憶化到 request／job 範圍**（容器裡是 `scoped`）：同一次請求裡個股頁、
 * prompt 與呈現層要序列化的是**同一份**快照，各自重組會讓它們對同一檔說出不同
 * 的立場——而「同一份快照決定輸出」正是這整層存在的理由。跨請求不共用：
 * 常駐 worker 不該沿用昨天的行情。
 */
class HealthSnapshotBuilder
{
    /** @var array<string, HealthInputSnapshot> */
    private array $memo = [];

    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly ChipDataService $chip,
        private readonly FundamentalsService $fundamentals,
        private readonly OrderInventoryAssessor $orderInventory,
        private readonly TechnicalIndicatorService $indicators,
    ) {}

    /** 只讀已快取資料，一次上游都不打。 */
    public function cachedFor(Instrument $instrument, int $bars): HealthInputSnapshot
    {
        return $this->memo["cached:{$instrument->id}:{$bars}"] ??= $this->build(
            $instrument,
            $bars,
            $this->cachedPrices($instrument, $bars),
            $this->cachedChipFlows($instrument),
            cachedOnly: true,
        );
    }

    /** 允許刷新上游（個股分析 job 用）。 */
    public function freshFor(Instrument $instrument, int $bars): HealthInputSnapshot
    {
        // 先刷新估值與序列，再組快照：兩者共用同一次抓取與同一列快取，
        // 之後的只讀查詢就會看到剛落地的那一列。
        $this->fundamentals->forInstrument($instrument);

        return $this->memo["fresh:{$instrument->id}:{$bars}"] ??= $this->build(
            $instrument,
            $bars,
            $this->marketData->dailyPrices($instrument->symbol, $bars),
            $this->chip->forInstrument($instrument),
            cachedOnly: false,
        );
    }

    /**
     * 兩個入口共用的組裝：差別只在價格與籌碼**從哪裡來**，以及取用政策這個旗標。
     *
     * @param  list<DailyPriceData>  $prices
     * @param  list<ChipFlowData>  $chipFlows
     */
    private function build(
        Instrument $instrument,
        int $bars,
        array $prices,
        array $chipFlows,
        bool $cachedOnly,
    ): HealthInputSnapshot {
        $valuation = $this->fundamentals->cachedValuationFor($instrument);
        $series = $this->orderInventory->seriesSignalsFor($instrument);
        $metrics = $series['metrics'];

        return new HealthInputSnapshot(
            symbol: $instrument->symbol,
            market: MarketResolver::region($instrument->symbol) === MarketRegion::Taiwan ? 'tw' : 'us',
            // **記實際根數，不是請求的根數。** 這個欄位是為了「跨消費端視窗一致」
            // 而存在的，用它判斷兩份判讀可不可比；記參數的話，一檔 DB 只有 49 列
            // 的標的照樣宣稱採計 80 根（實測 SPCX 就是這樣），而每一檔首次被搜尋、
            // 尚未跑過分析的標的都是這個處境。
            bars: count($prices),
            // K 棒不足時 calculate() 會拋，而「這一檔還沒有行情」不是例外情況——
            // 首次被搜尋到的標的必然如此。空指標讓 reader 判成技術面不可評估。
            indicators: $prices === [] ? [] : $this->indicators->calculate($prices),
            chipFlows: $chipFlows,
            fundamentals: $valuation['data'] ?? null,
            metrics: $metrics,
            valuationPercentiles: $this->fundamentals->valuationPercentiles($instrument),
            industryBucket: $series['industry_bucket'],
            priceAsOf: $prices === [] ? null : $prices[count($prices) - 1]->date,
            chipAsOf: $chipFlows === [] ? null : $chipFlows[count($chipFlows) - 1]->date,
            // 財報的 as_of 是**抓取時間**不是資料日期：估值的 data_as_of 是 PER 的
            // 公佈日，而使用者要判斷的是「這份判讀有多舊」。
            fundamentalsAsOf: $valuation['fetched_at'] ?? null,
            financialPeriod: $metrics?->latestPeriod,
            cachedOnly: $cachedOnly,
            // 漏填會讓 ETF 走回「等一下就有」那條路，但 ETF 永遠不會有 ROE。
            assetType: MarketResolver::assetType($instrument->symbol),
        );
    }

    /**
     * 已落地的行情，**直接讀 model**。
     *
     * 取最後 $bars 根：SQL 先降冪限筆再翻回升冪，指標計算一律吃升冪序列。
     *
     * @return list<DailyPriceData>
     */
    private function cachedPrices(Instrument $instrument, int $bars): array
    {
        if ($bars < 1) {
            return [];
        }

        return DailyPrice::query()
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('priced_at')
            // 同一天理論上只有一列（unique 索引），排序仍加 id 讓結果完全確定。
            ->orderByDesc('id')
            ->limit($bars)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (DailyPrice $row): DailyPriceData => new DailyPriceData(
                symbol: $instrument->symbol,
                date: $row->priced_at->toDateString(),
                // decimal cast 回傳 string，一律顯式轉型。
                open: (float) $row->open,
                high: (float) $row->high,
                low: (float) $row->low,
                close: (float) $row->close,
                volume: (int) $row->volume,
            ))
            ->all();
    }

    /**
     * 已落地的三大法人買賣超，**直接讀 model**（非台股沒有這種資料，回空）。
     *
     * 視窗沿用 `chip.history_days`，與 ChipDataService 讀快取時同一把尺。
     *
     * @return list<ChipFlowData>
     */
    private function cachedChipFlows(Instrument $instrument): array
    {
        if (! MarketResolver::isTaiwan($instrument->symbol)) {
            return [];
        }

        return ChipFlow::query()
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('traded_at')
            ->orderByDesc('id')
            ->limit((int) config('chip.history_days', 60))
            ->get()
            ->reverse()
            ->values()
            ->map(fn (ChipFlow $row): ChipFlowData => new ChipFlowData(
                date: $row->traded_at->toDateString(),
                foreignNet: (int) $row->foreign_net,
                trustNet: (int) $row->trust_net,
                dealerNet: (int) $row->dealer_net,
                totalNet: (int) $row->total_net,
            ))
            ->all();
    }
}
