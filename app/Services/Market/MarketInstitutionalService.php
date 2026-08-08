<?php

namespace App\Services\Market;

use App\Contracts\MarketInstitutionalProvider;
use App\Data\MarketInstitutionalData;
use Illuminate\Support\Facades\Cache;

/**
 * 全市場三大法人現貨買賣超的快取層（鏡像 FuturesDataService）。
 *
 * 大盤單一序列、盤後才變，量小，不落 DB 直接 Cache：有資料時快取較久，抓不到時短
 * TTL 節流重試，避免對免費層 FinMind 每次開頁重打。快取存純陣列而非 DTO：file/
 * database store 反序列化物件可能得到 __PHP_Incomplete_Class。
 */
class MarketInstitutionalService
{
    private const LATEST_KEY = 'market:institutional';

    private const SERIES_KEY = 'market:institutional_foreign_series';

    public function __construct(private readonly MarketInstitutionalProvider $provider) {}

    public function latest(): MarketInstitutionalData
    {
        $cached = Cache::get(self::LATEST_KEY);

        if (is_array($cached)) {
            return MarketInstitutionalData::fromArray($cached);
        }

        $data = $this->provider->latest();

        Cache::put(self::LATEST_KEY, $data->toArray(), now()->addMinutes($this->ttl($data->hasAny())));

        return $data;
    }

    /**
     * 外資現貨淨買賣超近 $days 個交易日序列（快取）。
     *
     * @return list<array{date: string, net: int}>
     */
    public function foreignNetSeries(int $days): array
    {
        $key = self::SERIES_KEY.':'.$days;
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $series = $this->provider->foreignNetSeries($days);

        Cache::put($key, $series, now()->addMinutes($this->ttl($series !== [])));

        return $series;
    }

    private function ttl(bool $hasData): int
    {
        return max(1, $hasData
            ? (int) config('brief.futures.cache_minutes', 60)
            : (int) config('brief.futures.failure_cache_minutes', 5));
    }
}
