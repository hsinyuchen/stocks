<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Support\FinMindGate;
use App\Support\FinMindTokenResolver;
use App\Support\MarketResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FinMindMarketDataProvider implements MarketDataProvider
{
    public function __construct(
        private readonly FinMindTokenResolver $tokens,
        private readonly int $timeoutSeconds = 20,
    ) {}

    public function quote(string $symbol): MarketQuoteData
    {
        $prices = $this->dailyPrices($symbol, 2);

        if ($prices === []) {
            throw new RuntimeException("FinMind returned no rows for {$symbol}.");
        }

        $last = $prices[count($prices) - 1];
        $previousClose = count($prices) >= 2 ? $prices[count($prices) - 2]->close : $last->close;
        $change = $last->close - $previousClose;
        $changePercent = $previousClose != 0.0 ? ($change / $previousClose) * 100 : 0.0;

        return new MarketQuoteData(
            symbol: strtoupper($symbol),
            price: round($last->close, 4),
            change: round($change, 4),
            changePercent: round($changePercent, 4),
            asOf: $last->date.'T00:00:00+08:00',
        );
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        // 免費層額度冷卻中：與 FinMind 失敗同樣拋出，由上層（CachedMarketDataProvider
        // 讀既有 DB 快取、或呼叫端的 try/catch）走既有降級路徑。
        if (FinMindGate::isTripped()) {
            throw new RuntimeException("FinMind cooldown active for {$symbol}.");
        }

        $query = [
            'dataset' => 'TaiwanStockPrice',
            'data_id' => MarketResolver::taiwanCode($symbol),
            'start_date' => CarbonImmutable::now()->subDays(max($days * 2, 30))->toDateString(),
        ];

        $token = $this->tokens->resolve();

        if ($token !== null && $token !== '') {
            $query['token'] = $token;
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->get('https://api.finmindtrade.com/api/v4/data', $query);

        if (FinMindGate::limited($response) || $response->failed()) {
            throw new RuntimeException("FinMind request for {$symbol} failed with status {$response->status()}.");
        }

        $rows = $response->json('data');

        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $prices = [];
        foreach ($rows as $row) {
            if (! isset($row['date'], $row['open'], $row['max'], $row['min'], $row['close'])) {
                continue;
            }

            $prices[] = new DailyPriceData(
                symbol: strtoupper($symbol),
                date: (string) $row['date'],
                open: (float) $row['open'],
                high: (float) $row['max'],
                low: (float) $row['min'],
                close: (float) $row['close'],
                volume: (int) round((float) ($row['Trading_Volume'] ?? 0)),
            );
        }

        return array_slice($prices, -$days);
    }
}
