<?php

namespace App\Services\Fundamentals;

use App\Support\FinMindGate;
use App\Support\FinMindTokenResolver;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 台股產業別。
 *
 * TaiwanStockInfo 不帶 data_id 一次回全表（實測 4308 檔、57 類），故一次抓取
 * 快取 7 天。逐檔查會讓 100 檔股池各多打一次 FinMind，而免費層額度會撞。
 *
 * 一檔可能有多個產業列（實測 3019 同時為「光電業」與「電子工業」）。框架的
 * 產業適用性要用較具體的分類，因此上位分類只在沒有更細分類時才採用。
 *
 * BROAD_CATEGORIES 中「電子工業」的多分類共存是實測結果；「其他」未實際
 * 觀察到與細分類並存，僅是假設同樣的邏輯成立——它是撿剩的統包分類，理應
 * 不該蓋過任何更具體的分類。
 */
class TaiwanIndustryResolver
{
    private const CACHE_KEY = 'finmind:industry_map';

    private const ENDPOINT = 'https://api.finmindtrade.com/api/v4/data';

    /** 上位分類。同一檔若另有其他分類，優先採用較細的那個。 */
    private const BROAD_CATEGORIES = ['電子工業', '其他'];

    public function __construct(private readonly FinMindTokenResolver $tokens) {}

    public function resolve(string $symbol): ?string
    {
        if (! MarketResolver::isTaiwan($symbol)) {
            return null;
        }

        return $this->map()[MarketResolver::taiwanCode($symbol)] ?? null;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string> stock_id => industry_category
     */
    private function map(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $map = [];

        if (FinMindGate::isTripped()) {
            Log::warning('finmind: industry map fetch skipped, circuit breaker tripped');
        } else {
            try {
                $response = Http::timeout(40)->get(self::ENDPOINT, array_filter([
                    'dataset' => 'TaiwanStockInfo',
                    'token' => $this->tokens->resolve() ?: null,
                ]));

                if (FinMindGate::limited($response)) {
                    Log::warning('finmind: industry map fetch rate limited');
                } elseif (! $response->successful()) {
                    Log::warning('finmind: industry map fetch rejected', ['status' => $response->status()]);
                } else {
                    foreach ((array) $response->json('data', []) as $row) {
                        $id = (string) ($row['stock_id'] ?? '');
                        $category = (string) ($row['industry_category'] ?? '');

                        if ($id === '' || $category === '') {
                            continue;
                        }

                        // 已有較具體的分類時，不讓上位分類覆蓋。
                        if (isset($map[$id]) && in_array($category, self::BROAD_CATEGORIES, true)) {
                            continue;
                        }

                        $map[$id] = $category;
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('finmind: industry map fetch failed', ['error' => $exception->getMessage()]);
            }
        }

        $days = $map === [] ? 0 : (int) config('order_inventory.industry_cache_days', 7);
        Cache::put(self::CACHE_KEY, $map, $days > 0 ? now()->addDays($days) : now()->addMinutes(10));

        return $map;
    }
}
