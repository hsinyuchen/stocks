<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YahooChartMarketDataProvider implements MarketDataProvider
{
    public function __construct(private readonly int $timeoutSeconds = 20) {}

    public function quote(string $symbol): MarketQuoteData
    {
        $url = 'https://query2.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol).'?range=5d&interval=1d';
        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; StockRadar/1.0)'])
            ->acceptJson()
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Yahoo chart request for {$symbol} failed with status {$response->status()}.");
        }

        $meta = $response->json('chart.result.0.meta');
        $meta = is_array($meta) ? $meta : [];

        $rows = $this->dailyCloses(
            $response->json('chart.result.0.timestamp'),
            $response->json('chart.result.0.indicators.quote.0.close'),
        );

        $regularMarketTime = $meta['regularMarketTime'] ?? null;
        $price = $meta['regularMarketPrice'] ?? null;

        if ($price === null) {
            return $this->quoteFromCloses($symbol, $rows, $regularMarketTime);
        }

        $price = (float) $price;
        $previousClose = $this->previousClose($rows, $regularMarketTime) ?? $price;
        $change = $price - $previousClose;
        $changePercent = $previousClose != 0.0 ? ($change / $previousClose) * 100 : 0.0;

        $asOf = $regularMarketTime !== null
            ? CarbonImmutable::createFromTimestampUTC((int) $regularMarketTime)->toIso8601String()
            : CarbonImmutable::now()->toIso8601String();

        return new MarketQuoteData(
            symbol: strtoupper($symbol),
            price: round($price, 4),
            change: round($change, 4),
            changePercent: round($changePercent, 4),
            asOf: $asOf,
        );
    }

    /**
     * 把 chart 回應的 timestamp／close 兩條平行陣列配成 (日期, 收盤) 序列。
     *
     * close 常有 null（Yahoo 的常態），但**必須先配對再丟棄**：先過濾 close 會
     * 重新編號，之後與未過濾的 timestamp 相配就整段位移，昨收會取到今天那一根。
     *
     * timestamp 缺席時回空序列——`quote()` 的日期規則沒有 timestamp 就無法成立，
     * 寧可退回「平盤／拋例外」，也不要再長出一套沒有日期概念的判準。
     *
     * @return list<array{date: string, close: float}>
     */
    private function dailyCloses(mixed $timestamps, mixed $closes): array
    {
        if (! is_array($timestamps) || ! is_array($closes)) {
            return [];
        }

        $rows = [];
        foreach ($timestamps as $index => $timestamp) {
            $close = $closes[$index] ?? null;

            if ($timestamp === null || $close === null) {
                continue;
            }

            $rows[] = [
                'date' => CarbonImmutable::createFromTimestampUTC((int) $timestamp)->toDateString(),
                'close' => (float) $close,
            ];
        }

        return $rows;
    }

    /**
     * 昨收：序列中日期早於行情當日的最後一根收盤。
     *
     * 不讀 `meta.previousClose` 與 `meta.chartPreviousClose`——前者實測不等於昨收
     * （AAPL 回 311.3、實際 313.45），後者是整段 range 的基準價（等於窗口第一根），
     * 採用它等於拿整個窗口在算漲跌幅。
     *
     * 也不能無腦取倒數第二根：盤中時最後一根是今天的未完成棒，倒數第二根才對；
     * 但序列最後一根不是今天時（休市、上游落後），倒數第二根會跳過一天。
     * 以日期比對兩種情況都對。
     *
     * 日期換算沿用 `dailyPrices()` 的慣例（timestamp 直接當 UTC 轉日期）：台美的
     * 日盤時間換成 UTC 都落在同一天，刻意不引進交易所時區處理。
     *
     * @param  list<array{date: string, close: float}>  $rows
     */
    private function previousClose(array $rows, mixed $regularMarketTime): ?float
    {
        if ($rows === []) {
            return null;
        }

        // regularMarketTime 缺席時以序列最後一根的日期當「當日」，等價於取倒數第二根。
        $marketDay = $regularMarketTime !== null
            ? CarbonImmutable::createFromTimestampUTC((int) $regularMarketTime)->toDateString()
            : $rows[count($rows) - 1]['date'];

        for ($index = count($rows) - 1; $index >= 0; $index--) {
            if ($rows[$index]['date'] < $marketDay) {
                return $rows[$index]['close'];
            }
        }

        return null;
    }

    /**
     * Fallback quote derived from the daily close series when the chart meta
     * carries no intraday `regularMarketPrice`.
     *
     * asOf 用序列最後一根的**資料日期**，不用 now()：這條路拿到的是歷史收盤，標成
     * 「現在」會讓報價卡上的時間與警報、損益的判斷都以為它是即時價。
     *
     * @param  list<array{date: string, close: float}>  $rows
     */
    private function quoteFromCloses(string $symbol, array $rows, mixed $regularMarketTime): MarketQuoteData
    {
        if ($rows === []) {
            throw new RuntimeException("Yahoo chart returned no rows for {$symbol}.");
        }

        $last = $rows[count($rows) - 1]['close'];
        $previousClose = $this->previousClose($rows, $regularMarketTime) ?? $last;
        $change = $last - $previousClose;
        $changePercent = $previousClose != 0.0 ? ($change / $previousClose) * 100 : 0.0;

        return new MarketQuoteData(
            symbol: strtoupper($symbol),
            price: round($last, 4),
            change: round($change, 4),
            changePercent: round($changePercent, 4),
            asOf: $rows[count($rows) - 1]['date'].'T00:00:00+00:00',
        );
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        // Yahoo range 檔位：以請求天數換算，寬鬆取上一檔，避免上游回不足量。
        // 252 ≈ 一年交易日。
        $range = match (true) {
            $days <= 252 => '1y',
            $days <= 504 => '2y',
            default => '5y',
        };
        $url = 'https://query2.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol)."?range={$range}&interval=1d";
        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; StockRadar/1.0)'])
            ->acceptJson()
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Yahoo chart request for {$symbol} failed with status {$response->status()}.");
        }

        $timestamps = $response->json('chart.result.0.timestamp');
        $quote = $response->json('chart.result.0.indicators.quote.0');

        if (! is_array($timestamps) || ! is_array($quote)) {
            return [];
        }

        $prices = [];
        foreach ($timestamps as $index => $timestamp) {
            $close = $quote['close'][$index] ?? null;
            $open = $quote['open'][$index] ?? null;
            $high = $quote['high'][$index] ?? null;
            $low = $quote['low'][$index] ?? null;

            if ($close === null || $open === null || $high === null || $low === null) {
                continue;
            }

            $prices[] = new DailyPriceData(
                symbol: strtoupper($symbol),
                date: CarbonImmutable::createFromTimestampUTC((int) $timestamp)->toDateString(),
                open: (float) $open,
                high: (float) $high,
                low: (float) $low,
                close: (float) $close,
                volume: (int) round((float) ($quote['volume'][$index] ?? 0)),
            );
        }

        return array_slice($prices, -$days);
    }
}
