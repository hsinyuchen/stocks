<?php

namespace App\Services\Analysis;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Data\ChipFlowData;
use App\Data\MarginFlowData;
use App\Data\MarketQuoteData;
use App\Data\NewsItemData;
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
 * 刻意只依賴分析必需的四個服務。基本面、籌碼、融資是呼叫端才需要的加值資料，
 * 疊在外層，避免這裡被撐成無所不包的上帝物件，也讓 analyze() 不必為了組脈絡
 * 而多背三個它用不到的依賴。
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
     *     rates: array{block: string, affected: list<array<string, mixed>>}
     * }
     */
    public function forSymbol(string $symbol, array $chipFlows = [], array $marginFlows = [], ?array $brokerBranch = null): array
    {
        $quote = $this->marketData->quote($symbol);
        $prices = $this->marketData->dailyPrices($symbol, self::PRICE_BARS);
        $news = $this->news->relatedNews($symbol, self::NEWS_LIMIT);
        $rates = $this->ratesContext($symbol);

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
        ];
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
    private function ratesContext(string $symbol): array
    {
        $market = MarketResolver::isTaiwan($symbol) ? 'tw' : 'us';

        // forAudience 已含 best-effort 例外處理，此處不需再包 try/catch。
        return $this->ratesNarrative->forAudience($market, [$symbol], 'symbol');
    }
}
