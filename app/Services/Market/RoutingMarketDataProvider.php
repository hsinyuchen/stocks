<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuoteData;
use App\Support\MarketResolver;
use RuntimeException;
use Throwable;

class RoutingMarketDataProvider implements MarketDataProvider
{
    public function __construct(
        private readonly MarketDataProvider $taiwan,
        private readonly MarketDataProvider $unitedStates,
        private readonly MarketDataProvider $fallback,
    ) {}

    /**
     * 報價的來源優先序與 {@see dailyPrices()} 刻意相反——台股尤其明顯。
     *
     * FinMind 的 `TaiwanStockPrice` 是**日線**資料集，當日收盤要數小時後才補上：
     * 2026-09-02 實測 13:30 收盤、14:05 查詢，最新一筆仍停在 09-01。而 quote()
     * 的語意是「現在的價格」，回一根昨天的 K 棒不是延遲，是錯的值——當時
     * 8299.TWO 畫面顯示 2,125（−1.85%），實際為 2,065（−2.82%）。同一個值還餵給
     * `AlertEvaluator` 的價格警報與 `PortfolioService` 的損益，錯得不只是一張報價卡。
     *
     * Yahoo chart 的 `meta.regularMarketPrice` 盤中即時、收盤後即為當日收盤，
     * 因此台股報價以它為主、FinMind 退為備援（Yahoo 缺該檔或整個掛掉時）。
     *
     * dailyPrices() 則維持 FinMind 優先：歷史序列要的是完整與除權息一致，
     * 不是最新那一根。
     */
    public function quote(string $symbol): MarketQuoteData
    {
        $failure = null;

        foreach ($this->quoteChain($symbol) as $provider) {
            try {
                return $provider->quote($symbol);
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        }

        throw $failure ?? new RuntimeException("No market data provider could quote {$symbol}.");
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $primary = $this->primaryFor($symbol);

        try {
            $prices = $primary->dailyPrices($symbol, $days);
        } catch (Throwable) {
            $prices = [];
        }

        if ($prices !== []) {
            return $prices;
        }

        return $this->fallback->dailyPrices($symbol, $days);
    }

    private function primaryFor(string $symbol): MarketDataProvider
    {
        return MarketResolver::isTaiwan($symbol) ? $this->taiwan : $this->unitedStates;
    }

    /**
     * 報價來源的嘗試順序，前者失敗才試下一個。見 {@see quote()} 的說明。
     *
     * @return list<MarketDataProvider>
     */
    private function quoteChain(string $symbol): array
    {
        return MarketResolver::isTaiwan($symbol)
            ? [$this->fallback, $this->taiwan]
            : [$this->unitedStates, $this->fallback];
    }
}
