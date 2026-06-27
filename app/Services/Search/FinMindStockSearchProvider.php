<?php

namespace App\Services\Search;

use App\Contracts\StockSearchProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FinMindStockSearchProvider implements StockSearchProvider
{
    private const CACHE_KEY = 'stock:search:tw-universe';

    private const MAX_RESULTS = 15;

    public function __construct(
        private readonly ?string $token,
        private readonly int $timeoutSeconds = 20,
    ) {}

    public function search(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $universe = $this->universe();

        if ($universe === []) {
            return [];
        }

        $needle = mb_strtolower($query);
        $upperNeedle = strtoupper($query);

        $matches = [];
        foreach ($universe as $row) {
            $bareCode = strtoupper(explode('.', $row['symbol'])[0]);

            $nameHit = str_contains(mb_strtolower($row['name']), $needle);
            $codeHit = str_contains(strtoupper($row['symbol']), $upperNeedle)
                || str_contains($bareCode, $upperNeedle);

            if ($nameHit || $codeHit) {
                $matches[] = $row;
            }

            if (count($matches) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $matches;
    }

    /**
     * Cached (~24h) plain-array TW listed universe. Caches primitives only —
     * never DTO objects — to avoid __PHP_Incomplete_Class on deserialization.
     *
     * @return list<array{symbol:string,name:string,market:string}>
     */
    private function universe(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $universe = $this->fetchUniverse();

        // Do not cache failures (empty result) so the next call retries.
        if ($universe !== []) {
            Cache::put(self::CACHE_KEY, $universe, now()->addHours(24));
        }

        return $universe;
    }

    /**
     * @return list<array{symbol:string,name:string,market:string}>
     */
    private function fetchUniverse(): array
    {
        $query = ['dataset' => 'TaiwanStockInfo'];

        if ($this->token !== null && $this->token !== '') {
            $query['token'] = $this->token;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->acceptJson()
                ->get('https://api.finmindtrade.com/api/v4/data', $query);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $rows = $response->json('data');

        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $byCode = [];
        foreach ($rows as $row) {
            $code = isset($row['stock_id']) ? trim((string) $row['stock_id']) : '';
            $name = isset($row['stock_name']) ? trim((string) $row['stock_name']) : '';

            if ($code === '' || $name === '' || isset($byCode[$code])) {
                continue;
            }

            $suffix = (($row['type'] ?? '') === 'tpex') ? '.TWO' : '.TW';

            $byCode[$code] = [
                'symbol' => $code.$suffix,
                'name' => $name,
                'market' => 'TW',
            ];
        }

        return array_values($byCode);
    }
}
