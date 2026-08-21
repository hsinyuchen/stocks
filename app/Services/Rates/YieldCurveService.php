<?php

namespace App\Services\Rates;

use App\Contracts\YieldCurveProvider;
use App\Data\YieldCurveData;
use Illuminate\Support\Facades\Cache;

/**
 * 殖利率曲線的快取層。
 *
 * 曲線是全站共用的單一序列、盤後才變、資料量小（2 檔 × 130 根），因此不落 DB
 * 而直接用 Cache——落 DB 需經 Instrument，正是本功能刻意迴避的污染路徑。
 * 抓不到時用較短 TTL 節流重試，避免每次開頁重打上游。
 *
 * 快取存純陣列而非 DTO 物件：file/database store 反序列化物件可能得到
 * __PHP_Incomplete_Class。
 */
class YieldCurveService
{
    private const CACHE_KEY = 'rates:curve';

    public function __construct(private readonly YieldCurveProvider $provider) {}

    public function curve(): YieldCurveData
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return YieldCurveData::fromArray($cached);
        }

        $curve = $this->provider->curve(
            $this->marketSymbols(),
            max(1, (int) config('rates.history_days', 130)),
        );

        $minutes = $curve->hasAny()
            ? (int) config('rates.cache_minutes', 60)
            : (int) config('rates.failure_cache_minutes', 5);

        Cache::put(self::CACHE_KEY, $curve->toArray(), now()->addMinutes(max(1, $minutes)));

        return $curve;
    }

    /**
     * 走行情來源的天期 map。
     *
     * source 是預留給未來 FRED（2Y）的接縫：非 'market' 的天期不送給行情 provider，
     * 屆時由另一個實作補上，判定層不需要知道差別。
     *
     * @return array<string, string>
     */
    private function marketSymbols(): array
    {
        $out = [];

        foreach ((array) config('rates.tenors', []) as $key => $tenor) {
            if ((string) ($tenor['source'] ?? 'market') !== 'market') {
                continue;
            }

            $symbol = (string) ($tenor['symbol'] ?? '');

            if ($symbol !== '') {
                $out[(string) $key] = $symbol;
            }
        }

        return $out;
    }
}
