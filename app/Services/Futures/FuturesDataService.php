<?php

namespace App\Services\Futures;

use App\Contracts\FuturesDataProvider;
use App\Data\FuturesMarketData;
use Illuminate\Support\Facades\Cache;

/**
 * 期貨大盤快照的快取層。
 *
 * 大盤單一序列、盤後才變，量小，因此不落 DB，直接用 Cache：有資料時快取較久，
 * 抓不到（provider 回 empty）時短 TTL 節流重試，避免對免費層 FinMind 每次開頁重打。
 *
 * 快取存純陣列而非 DTO 物件：file/database store 反序列化物件可能得到
 * __PHP_Incomplete_Class。
 */
class FuturesDataService
{
    private const CACHE_KEY = 'futures:snapshot';

    private const SERIES_CACHE_KEY = 'futures:foreign_net_oi_series';

    public function __construct(private readonly FuturesDataProvider $provider) {}

    public function snapshot(): FuturesMarketData
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return FuturesMarketData::fromArray($cached);
        }

        $snapshot = $this->provider->snapshot();

        $minutes = $snapshot->hasAny()
            ? (int) config('brief.futures.cache_minutes', 60)
            : (int) config('brief.futures.failure_cache_minutes', 5);

        Cache::put(self::CACHE_KEY, $snapshot->toArray(), now()->addMinutes(max(1, $minutes)));

        return $snapshot;
    }

    /**
     * 外資期貨淨留倉近 $days 個交易日序列（快取）。
     *
     * 序列已是純陣列，直接快取無 DTO 反序列化問題。抓不到（[]）時用較短 TTL 節流重試。
     *
     * @return list<array{date: string, net: int}>
     */
    public function foreignNetOiSeries(int $days): array
    {
        $key = self::SERIES_CACHE_KEY.':'.$days;
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $series = $this->provider->foreignNetOiSeries($days);

        $minutes = $series !== []
            ? (int) config('brief.futures.cache_minutes', 60)
            : (int) config('brief.futures.failure_cache_minutes', 5);

        Cache::put($key, $series, now()->addMinutes(max(1, $minutes)));

        return $series;
    }
}
