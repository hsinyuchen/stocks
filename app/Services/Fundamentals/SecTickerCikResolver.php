<?php

namespace App\Services\Fundamentals;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ticker → CIK 對照。
 *
 * SEC 的 companyfacts 端點以 CIK 定位公司，不吃 ticker。對照表一次抓全表
 * （約一萬筆）快取 7 天——逐檔查會讓每檔股票各多打一次 SEC，而 SEC 官方
 * 限制 10 req/s 且要求可識別的 User-Agent。
 *
 * best-effort：抓不到回 null，不拋。查不到的代號可能是新上市或非美股，
 * 呼叫端據此降級即可。
 */
class SecTickerCikResolver
{
    private const CACHE_KEY = 'sec:ticker_cik_map';

    /**
     * @return string|null 10 碼零填補的 CIK，如 '0001045810'
     */
    public function resolve(string $ticker): ?string
    {
        $map = $this->map();
        $key = strtoupper(trim($ticker));

        return $map[$key] ?? null;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string> TICKER => CIK
     */
    private function map(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $config = (array) config('order_inventory.sec', []);
        $map = [];

        try {
            $response = Http::withHeaders(['User-Agent' => (string) ($config['user_agent'] ?? '')])
                ->timeout((int) ($config['timeout_seconds'] ?? 40))
                ->get((string) ($config['ticker_map_url'] ?? ''));

            if ($response->successful()) {
                foreach ((array) $response->json() as $row) {
                    $ticker = strtoupper((string) ($row['ticker'] ?? ''));
                    $cik = $row['cik_str'] ?? null;

                    if ($ticker !== '' && is_numeric($cik)) {
                        $map[$ticker] = str_pad((string) (int) $cik, 10, '0', STR_PAD_LEFT);
                    }
                }
            }
        } catch (Throwable $exception) {
            Log::warning('sec: ticker map fetch failed', ['error' => $exception->getMessage()]);
        }

        // 抓不到時仍寫入空表並用較短 TTL，避免每次查詢都重打 SEC；
        // 成功才用完整 TTL。
        $days = $map === [] ? 0 : (int) ($config['ticker_map_cache_days'] ?? 7);
        Cache::put(self::CACHE_KEY, $map, $days > 0 ? now()->addDays($days) : now()->addMinutes(10));

        return $map;
    }
}
