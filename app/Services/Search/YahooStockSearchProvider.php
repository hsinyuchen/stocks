<?php

namespace App\Services\Search;

use App\Contracts\StockSearchProvider;
use Illuminate\Support\Facades\Http;
use Throwable;

class YahooStockSearchProvider implements StockSearchProvider
{
    private const MAX_RESULTS = 15;

    /**
     * quoteType values kept from the Yahoo search response.
     *
     * @var list<string>
     */
    private const ALLOWED_QUOTE_TYPES = ['EQUITY', 'ETF'];

    /**
     * US exchange codes kept from the Yahoo search response.
     *
     * @var list<string>
     */
    private const US_EXCHANGES = ['NMS', 'NYQ', 'NGM', 'NCM', 'ASE', 'PCX', 'BTS'];

    public function __construct(private readonly int $timeoutSeconds = 12) {}

    public function search(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; StockRadar/1.0)'])
                ->acceptJson()
                ->get('https://query2.finance.yahoo.com/v1/finance/search', ['q' => $query]);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $quotes = $response->json('quotes');

        if (! is_array($quotes)) {
            return [];
        }

        $results = [];
        foreach ($quotes as $quote) {
            if (! is_array($quote)) {
                continue;
            }

            $symbol = $quote['symbol'] ?? null;
            $quoteType = $quote['quoteType'] ?? null;
            $exchange = $quote['exchange'] ?? null;

            if (! is_string($symbol) || $symbol === '') {
                continue;
            }

            if (! in_array($quoteType, self::ALLOWED_QUOTE_TYPES, true)) {
                continue;
            }

            if (! in_array($exchange, self::US_EXCHANGES, true)) {
                continue;
            }

            $results[] = [
                'symbol' => $symbol,
                'name' => $quote['shortname'] ?? $quote['longname'] ?? $symbol,
                'market' => 'US',
                'exchange' => $exchange,
            ];

            if (count($results) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $results;
    }
}
