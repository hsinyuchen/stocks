<?php

namespace Tests\Feature\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Services\Market\CachedMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CachedMarketDataCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 可計數呼叫次數的 stub upstream。
     * 回傳長度 = min(請求天數, $maxDays)，以模擬上游最多能給的歷史深度；
     * 淺填（請求 120）與深抓（請求 1300）因此回傳不同 row 數，
     * coverage 判斷才有可驗證的行為差異。
     */
    private function upstream(int $maxDays): MarketDataProvider
    {
        return new class($maxDays) implements MarketDataProvider
        {
            public int $calls = 0;

            public function __construct(private readonly int $maxDays) {}

            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, CarbonImmutable::now()->toIso8601String());
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                $this->calls++;
                $length = min($days, $this->maxDays);
                $out = [];
                for ($i = $length - 1; $i >= 0; $i--) {
                    $date = CarbonImmutable::now()->subDays($i)->toDateString();
                    $out[] = new DailyPriceData($symbol, $date, 99.0, 101.0, 98.0, 100.0, 1000);
                }

                return $out;
            }
        };
    }

    public function test_fresh_but_shallow_cache_triggers_backfill(): void
    {
        $upstream = $this->upstream(1300);
        $provider = new CachedMarketDataProvider($upstream, ttlMinutes: 720);

        // 先以 120 天填快取（fresh）
        $provider->dailyPrices('2330.TW', 120);
        $this->assertSame(1, $upstream->calls);

        // fresh 但 coverage 不足：請求 1300 天必須重抓，且回傳涵蓋足量
        $result = $provider->dailyPrices('2330.TW', 1300);

        $this->assertSame(2, $upstream->calls);
        $this->assertGreaterThan(120, count($result));
    }

    public function test_fresh_and_covered_cache_does_not_refetch(): void
    {
        $upstream = $this->upstream(1300);
        $provider = new CachedMarketDataProvider($upstream, ttlMinutes: 720);

        $provider->dailyPrices('2330.TW', 1300);
        $provider->dailyPrices('2330.TW', 120);
        $provider->dailyPrices('2330.TW', 1300);

        $this->assertSame(1, $upstream->calls);
    }
}
