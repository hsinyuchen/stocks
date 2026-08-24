<?php

namespace App\Services\Analysis;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Data\ChipFlowData;
use App\Data\MarginFlowData;
use App\Data\MarketQuoteData;
use App\Data\NewsItemData;
use App\Data\OrderInventoryAssessment;
use App\Models\Instrument;
use App\Services\Fundamentals\OrderInventoryAssessor;
use App\Services\Rates\RatesNarrative;
use App\Services\SignalEngine;
use App\Services\TechnicalIndicatorService;
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
 * 組脈絡而多背三個它用不到的依賴。利率敘述與訂單／庫存評級則相反——個股分析與
 * 個股問答都要，且兩邊都曾經各自漏接過，所以放在這裡由單一來源供給。
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
     *     order_inventory: array{assessment: OrderInventoryAssessment, peer_samples: int}|null
     * }
     */
    public function forSymbol(string $symbol, array $chipFlows = [], array $marginFlows = [], ?array $brokerBranch = null, string $locale = 'zh'): array
    {
        $quote = $this->marketData->quote($symbol);
        $prices = $this->marketData->dailyPrices($symbol, self::PRICE_BARS);
        $news = $this->news->relatedNews($symbol, self::NEWS_LIMIT);
        $rates = $this->ratesContext($symbol, $locale);
        $orderInventory = $this->orderInventoryContext($symbol);

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
