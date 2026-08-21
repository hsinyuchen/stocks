<?php

namespace App\Services\Rates;

use App\Contracts\YieldCurveProvider;
use App\Data\YieldCurveData;
use App\Services\Market\YahooChartMarketDataProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 以 Yahoo chart 取美債殖利率（^TNX、^IRX 等）。
 *
 * 刻意直接持有 YahooChartMarketDataProvider，不使用容器綁定的 MarketDataProvider：
 * 後者是 CachedMarketDataProvider，其 dailyPrices() 必經 Instrument::createOrFirst()，
 * 會把 ^TNX 建成 asset_type=index 的可交易標的並污染全站字典與搜尋。實測已驗證。
 *
 * 代價是不走 daily_prices 表快取，改由 YieldCurveService 以 Cache 承接——資料量小
 * （2 檔 × 130 根）、可重算、無歷史查詢需求，與 FuturesDataService 同模式。
 */
class YahooYieldCurveProvider implements YieldCurveProvider
{
    public function __construct(
        private readonly YahooChartMarketDataProvider $upstream = new YahooChartMarketDataProvider,
    ) {}

    public function curve(array $tenors, int $days): YieldCurveData
    {
        $byTenor = [];

        foreach ($tenors as $key => $symbol) {
            $closes = $this->closesFor((string) $symbol, $days);

            // 個別天期失敗只略過該天期：四天期設定下不該因 ^TYX 缺料
            // 就讓 10Y-3M 也不可用。全部失敗時 aligned() 自然回 empty()。
            if ($closes !== []) {
                $byTenor[(string) $key] = $closes;
            }
        }

        return YieldCurveData::aligned($byTenor);
    }

    /**
     * @return array<string, float> date => close
     */
    private function closesFor(string $symbol, int $days): array
    {
        try {
            $prices = $this->upstream->dailyPrices($symbol, $days);
        } catch (Throwable $exception) {
            Log::warning('rates: tenor fetch failed', [
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $out = [];

        foreach ($prices as $price) {
            $close = is_array($price) ? ($price['close'] ?? null) : ($price->close ?? null);
            $date = is_array($price) ? ($price['date'] ?? null) : ($price->date ?? null);

            if (is_numeric($close) && is_string($date)) {
                $out[$date] = (float) $close;
            }
        }

        return $out;
    }
}
