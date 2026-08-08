<?php

namespace App\Services\Analysis;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Data\ChipFlowData;
use App\Data\MarginFlowData;
use App\Data\MarketQuoteData;
use App\Data\NewsItemData;
use App\Services\SignalEngine;
use App\Services\TechnicalIndicatorService;

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
    ) {}

    /**
     * 取得個股的行情、技術指標、規則訊號與相關新聞。
     *
     * quote 與 news 保留 DTO 原型（呼叫端各有不同的序列化需求）。has_prices 為
     * false 時 technical_snapshot 為空陣列、rule_signal 為 insufficient_data，
     * 呼叫端據此決定要不要繼續往下走。
     *
     * @param  list<ChipFlowData>  $chipFlows
     * @param  list<MarginFlowData>  $marginFlows
     * @return array{
     *     symbol: string,
     *     quote: MarketQuoteData,
     *     technical_snapshot: array<string, mixed>,
     *     rule_signal: array<string, mixed>,
     *     news: list<NewsItemData>,
     *     data_as_of: string,
     *     has_prices: bool
     * }
     */
    public function forSymbol(string $symbol, array $chipFlows = [], array $marginFlows = []): array
    {
        $quote = $this->marketData->quote($symbol);
        $prices = $this->marketData->dailyPrices($symbol, self::PRICE_BARS);
        $news = $this->news->relatedNews($symbol, self::NEWS_LIMIT);

        if ($prices === []) {
            return [
                'symbol' => $symbol,
                'quote' => $quote,
                'technical_snapshot' => [],
                'rule_signal' => [
                    'stance' => 'insufficient_data',
                    'score' => 0,
                    'reasons' => ['缺少價格歷史資料，暫時無法完成個股分析。'],
                ],
                'news' => $news,
                'data_as_of' => $quote->asOf,
                'has_prices' => false,
            ];
        }

        $technicalSnapshot = $this->indicators->calculate($prices);

        return [
            'symbol' => $symbol,
            'quote' => $quote,
            'technical_snapshot' => $technicalSnapshot,
            'rule_signal' => $this->signals->evaluate($technicalSnapshot, $chipFlows, $marginFlows),
            'news' => $news,
            'data_as_of' => $quote->asOf,
            'has_prices' => true,
        ];
    }
}
