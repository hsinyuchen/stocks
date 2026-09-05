<?php

namespace App\Services\Analysis;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Data\ChipFlowData;
use App\Data\HealthInputSnapshot;
use App\Data\IndustryMomentum;
use App\Data\LongTermRead;
use App\Data\MarginFlowData;
use App\Data\MarketQuoteData;
use App\Data\NewsItemData;
use App\Data\OrderInventoryAssessment;
use App\Data\ShortTermRead;
use App\Data\SocialArbitrage;
use App\Models\Instrument;
use App\Services\Fundamentals\IndustryMomentumSampler;
use App\Services\Fundamentals\OrderInventoryAssessor;
use App\Services\Health\HealthSnapshotBuilder;
use App\Services\Health\LongTermHealthReader;
use App\Services\Health\ShortTermHealthReader;
use App\Services\Rates\RatesNarrative;
use App\Services\SignalEngine;
use App\Services\Social\SocialArbitrageAssessor;
use App\Services\TechnicalIndicatorService;
use App\Support\CompletedBars;
use App\Support\MarketResolver;

/**
 * 個股脈絡的唯一組裝來源。
 *
 * 從 StockAnalysisService::analyze() 抽出來，因為個股問答也要同一份脈絡。若各自
 * 實作，「空價格如何降級」「取幾根 K 棒」「取幾則新聞」「指標怎麼算」會存在兩份
 * 而必然漂移——問答與分析對同一檔股票給出不同數字，是最難查的那種 bug。
 *
 * 刻意只依賴「兩個消費端都要」的服務。基本面估值、籌碼、融資是呼叫端才需要的
 * 加值資料，疊在外層，避免這裡被撐成無所不包的上帝物件，也讓 analyze() 不必為了
 * 組脈絡而多背三個它用不到的依賴。利率敘述、訂單／庫存評級、社交套利分類與產業
 * 動能則相反——個股分析與個股問答都要，且前兩者都曾經各自漏接過，所以放在這裡由
 * 單一來源供給。
 */
final class SymbolContextService
{
    /** 技術指標的回看視窗。MACD 需要約 50 根暖身，80 根是既有值。 */
    private const PRICE_BARS = 80;

    private const NEWS_LIMIT = 5;

    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly NewsProvider $news,
        private readonly TechnicalIndicatorService $indicators,
        private readonly SignalEngine $signals,
        private readonly RatesNarrative $ratesNarrative,
        private readonly OrderInventoryAssessor $orderInventory,
        private readonly SocialArbitrageAssessor $socialArbitrage,
        private readonly IndustryMomentumSampler $industryMomentum,
        private readonly HealthSnapshotBuilder $healthSnapshots,
        private readonly ShortTermHealthReader $shortTermHealth,
        private readonly LongTermHealthReader $longTermHealth,
    ) {}

    /**
     * 取得個股的行情、技術指標、規則訊號與相關新聞。
     *
     * quote 與 news 保留 DTO 原型（呼叫端各有不同的序列化需求）。has_prices 為
     * false 時 technical_snapshot 為空陣列、rule_signal 為 insufficient_data，
     * 呼叫端據此決定要不要繼續往下走。
     *
     * $brokerBranch 為券商分點主力摘要（BrokerBranchDataService::summaryFor 的產物），
     * 由呼叫端傳入並直接掛在 rule_signal.broker_branch。它已是聚合摘要而非原始序列，
     * 故不經 SignalEngine 計算（保持 stance/score 語意不變）。null 代表無此資料。
     *
     * $locale 只影響 rates.block 的敘述語言（RatesNarrative 依 locale 產生英/中文句子）；
     * 其餘欄位維持原型或既有語意，由呼叫端各自決定要不要在組 prompt 時翻譯。
     *
     * @param  list<ChipFlowData>  $chipFlows
     * @param  list<MarginFlowData>  $marginFlows
     * @param  array<string, mixed>|null  $brokerBranch
     * @return array{
     *     symbol: string,
     *     quote: MarketQuoteData,
     *     technical_snapshot: array<string, mixed>,
     *     rule_signal: array<string, mixed>,
     *     news: list<NewsItemData>,
     *     data_as_of: string,
     *     has_prices: bool,
     *     rates: array{block: string, affected: list<array<string, mixed>>},
     *     order_inventory: array{assessment: OrderInventoryAssessment, peer_samples: int}|null,
     *     social: array{arbitrage: SocialArbitrage, momentum: IndustryMomentum}|null,
     *     health: array{short: ShortTermRead, long: LongTermRead, snapshot: HealthInputSnapshot}|null
     * }
     */
    public function forSymbol(string $symbol, array $chipFlows = [], array $marginFlows = [], ?array $brokerBranch = null, string $locale = 'zh'): array
    {
        $quote = $this->marketData->quote($symbol);
        // 判讀會隨分析落 DB 且不再重算，只能建立在已完成的 K 棒上。見 CompletedBars。
        $prices = CompletedBars::only($this->marketData->dailyPrices($symbol, self::PRICE_BARS));
        $news = $this->news->relatedNews($symbol, self::NEWS_LIMIT);
        $rates = $this->ratesContext($symbol, $locale);
        $orderInventory = $this->orderInventoryContext($symbol);
        // 仍必須排在 orderInventoryContext() 之後，但理由已經縮小到只剩一種：
        // **這一行是本方法唯一會去抓上游的步驟**。社交套利的營收／毛利兩條腿與
        // 產業動能的產業別都走只讀入口，序列從未落地的標的（第一次被分析）在它們
        // 眼裡就是「沒有」。順序反過來，首次分析的那三項會全部退化成不可評估，
        // 而使用者看到的正是第一份報告。
        //
        // 已經**不再**是「快取過期」那個理由：兩個只讀入口現在各用序列自己的新鮮度
        // 視窗（order_inventory.series_freshness_days／industry_momentum.freshness_days），
        // 不再需要上一行順手刷新 fetched_at。昨天抓過的標的兩種順序結果相同。
        // 這條順序由 SocialArbitragePromptTest::the_symbol_context_carries_the_social_assessment
        // 釘住（那個 fixture 一列 fundamentals 都沒有）。
        $social = $this->socialContext($symbol);
        $health = $this->healthContext($symbol);

        if ($prices === []) {
            return [
                'symbol' => $symbol,
                'quote' => $quote,
                'technical_snapshot' => [],
                'rule_signal' => $this->withBrokerBranch([
                    'stance' => 'insufficient_data',
                    'score' => 0,
                    'reasons' => ['缺少價格歷史資料，暫時無法完成個股分析。'],
                ], $brokerBranch),
                'news' => $news,
                'data_as_of' => $quote->asOf,
                'has_prices' => false,
                'rates' => $rates,
                'order_inventory' => $orderInventory,
                'social' => $social,
                'health' => $health,
            ];
        }

        $technicalSnapshot = $this->indicators->calculate($prices);

        return [
            'symbol' => $symbol,
            'quote' => $quote,
            'technical_snapshot' => $technicalSnapshot,
            'rule_signal' => $this->withBrokerBranch(
                $this->signals->evaluate($technicalSnapshot, $chipFlows, $marginFlows),
                $brokerBranch,
            ),
            'news' => $news,
            'data_as_of' => $quote->asOf,
            'has_prices' => true,
            'rates' => $rates,
            'order_inventory' => $orderInventory,
            'social' => $social,
            'health' => $health,
        ];
    }

    /**
     * 短線／中長線體質判讀（best-effort，查無標的回 null）。
     *
     * 與 orderInventoryContext()／socialContext() 一樣要自己以代號反查 Instrument，
     * 理由相同：`forSymbol()` 的輸入只有代號，而快照層吃 `Instrument`。查無標的時
     * 回 null 而非建檔——搜尋結果頁可能對尚未建檔的代號組脈絡。
     *
     * **走 `freshFor()` 不是 `cachedFor()`**：本方法的兩個消費端（個股分析、個股
     * 問答）都跑在有自己 timeout 的 queued job 裡，而 `cachedFor()` 是為同步 web
     * 請求準備的零上游入口（見 HealthSnapshotBuilder 的 docblock）。首次被分析的
     * 標的在 `cachedFor()` 眼裡什麼都沒有，而使用者看到的正是第一份報告。
     *
     * 快照層會再取一次行情、籌碼與財報，與本方法上面那幾行重複——那些重複由各
     * 服務自身的新鮮度視窗吸收（`CachedMarketDataProvider` 在 TTL 內直接讀 DB、
     * `ChipDataService`／`FundamentalsService` 同理），不會變成第二次上游呼叫。
     * 換成把已取到的序列傳進去，就得在快照層開一個繞過它自己取用政策的入口，
     * 「同一份快照決定輸出」這個不變式會失去唯一的組裝點。
     *
     * 視窗用 self::PRICE_BARS：判讀必須與同一份脈絡裡的 rule_signal 看同一段
     * K 棒，否則同一次分析裡兩處會對同一檔給出不同的技術立場。
     *
     * @return array{short: ShortTermRead, long: LongTermRead, snapshot: HealthInputSnapshot}|null
     */
    private function healthContext(string $symbol): ?array
    {
        $instrument = Instrument::query()->where('symbol', $symbol)->first();

        if ($instrument === null) {
            return null;
        }

        $snapshot = $this->healthSnapshots->freshFor($instrument, self::PRICE_BARS);

        return [
            'short' => $this->shortTermHealth->read($snapshot),
            'long' => $this->longTermHealth->read($snapshot),
            'snapshot' => $snapshot,
        ];
    }

    /**
     * 社交套利分類與產業動能（best-effort，查無標的回 null）。
     *
     * 與 orderInventoryContext() 同樣要自己以代號反查 Instrument，理由相同：
     * `forSymbol()` 的輸入只有代號，而兩個評估器都吃 `Instrument`（要用
     * `instrument_id` 限縮新聞、行情、籌碼與財報快取）。查無標的時回 null 而非
     * 建檔——搜尋結果頁可能對尚未建檔的代號組脈絡。
     *
     * 兩者合成一個鍵而不是兩個：它們同時出現、同時缺席（同一次反查的產物），
     * 消費端只要判斷一次「有沒有社交面向」就能同時決定兩個區塊與那份引用紀律
     * 要不要輸出。
     *
     * `SocialArbitrageAssessor::forInstrument()` 全程只讀、一次上游都不打（見該
     * 類別 docblock），所以這裡不必另設「只讀入口」；產業動能則刻意走
     * `cachedFor()` 而不是 `forInstrument()`，後者要呼叫端先弄到一份序列，而
     * 過期時會就地抓上游。
     *
     * 兩者都是只讀入口，序列從未落地時一律回「不可評估／產業未知」，所以在
     * `forSymbol()` 裡仍排在 `orderInventoryContext()` 之後——那是唯一會抓取的步驟，
     * 詳見該處註解。
     *
     * @return array{arbitrage: SocialArbitrage, momentum: IndustryMomentum}|null
     */
    private function socialContext(string $symbol): ?array
    {
        $instrument = Instrument::query()->where('symbol', $symbol)->first();

        if ($instrument === null) {
            return null;
        }

        return [
            'arbitrage' => $this->socialArbitrage->forInstrument($instrument),
            'momentum' => $this->industryMomentum->cachedFor($instrument),
        ];
    }

    /**
     * 訂單／庫存判斷（best-effort，無資料回 null）。
     *
     * 這裡要自己以代號反查 Instrument：`forSymbol()` 的輸入只有代號，而評級的 IO
     * 邊界吃 `Instrument`——它要用 `instrument_id` 限縮財報列、並把本次評級寫回
     * 那一列。把 Instrument 加進 `forSymbol()` 的參數不划算（已有五個參數，且兩個
     * 呼叫端只有一個手上有 model）。
     *
     * 查無標的時回 null 而非建檔：搜尋結果頁可能對尚未建檔的代號組脈絡，為了評級
     * 去寫一筆 instruments 是副作用外溢。
     *
     * @return array{assessment: OrderInventoryAssessment, peer_samples: int}|null
     */
    private function orderInventoryContext(string $symbol): ?array
    {
        $instrument = Instrument::query()->where('symbol', $symbol)->first();

        return $instrument === null ? null : $this->orderInventory->forInstrument($instrument);
    }

    /**
     * 把券商分點主力摘要掛進 rule_signal（正交維度，不影響 stance/score）。
     * null 時完全不加欄位，與 chip/margin 缺資料的處理一致。
     *
     * @param  array<string, mixed>  $ruleSignal
     * @param  array<string, mixed>|null  $brokerBranch
     * @return array<string, mixed>
     */
    private function withBrokerBranch(array $ruleSignal, ?array $brokerBranch): array
    {
        if (is_array($brokerBranch)) {
            $ruleSignal['broker_branch'] = $brokerBranch;
        }

        return $ruleSignal;
    }

    /**
     * 該檔所處的利率環境與傳導歸屬（best-effort）。
     *
     * 市場由代號推導：美股走折現率直接鏈（板塊輪動），台股走美元與外資流向的
     * 間接鏈。抓不到時只標無法取得，其餘脈絡照常。
     *
     * @return array{block: string, affected: list<array<string, mixed>>}
     */
    private function ratesContext(string $symbol, string $locale = 'zh'): array
    {
        $market = MarketResolver::isTaiwan($symbol) ? 'tw' : 'us';

        // forAudience 已含 best-effort 例外處理，此處不需再包 try/catch。
        return $this->ratesNarrative->forAudience($market, [$symbol], 'symbol', $locale);
    }
}
