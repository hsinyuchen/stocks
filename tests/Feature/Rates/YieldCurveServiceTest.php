<?php

namespace Tests\Feature\Rates;

use App\Contracts\YieldCurveProvider;
use App\Data\YieldCurveData;
use App\Services\Fake\FakeYieldCurveProvider;
use App\Services\Rates\YieldCurveService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class YieldCurveServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** 記錄呼叫次數的 provider，用來驗證快取確實避免了重複抓取。 */
    private function countingProvider(YieldCurveData $curve): YieldCurveProvider
    {
        return new class($curve) implements YieldCurveProvider
        {
            public int $calls = 0;

            public function __construct(private readonly YieldCurveData $curve) {}

            public function curve(array $tenors, int $days): YieldCurveData
            {
                $this->calls++;

                return $this->curve;
            }
        };
    }

    private function sampleCurve(): YieldCurveData
    {
        return YieldCurveData::aligned([
            '10y' => ['2026-08-03' => 4.50, '2026-08-04' => 4.70],
            '3m' => ['2026-08-03' => 3.50, '2026-08-04' => 3.60],
        ]);
    }

    public function test_second_call_is_served_from_cache(): void
    {
        $provider = $this->countingProvider($this->sampleCurve());
        $service = new YieldCurveService($provider);

        $first = $service->curve();
        $second = $service->curve();

        $this->assertSame(1, $provider->calls);
        $this->assertSame($first->dates, $second->dates);
        $this->assertEqualsWithDelta(110.0, $second->spreadBp('10y', '3m'), 0.01);
    }

    public function test_failure_is_cached_with_the_shorter_throttle(): void
    {
        config()->set('rates.cache_minutes', 60);
        config()->set('rates.failure_cache_minutes', 5);

        $provider = $this->countingProvider(YieldCurveData::empty());
        $service = new YieldCurveService($provider);

        $curve = $service->curve();

        $this->assertFalse($curve->hasAny());
        // 失敗也要寫快取，否則每次開頁都重打上游。
        $service->curve();
        $this->assertSame(1, $provider->calls);
    }

    public function test_only_market_sourced_tenors_are_requested(): void
    {
        // source 非 market 的天期（未來的 FRED）目前不送給 Yahoo provider。
        config()->set('rates.tenors', [
            '10y' => ['symbol' => '^TNX', 'source' => 'market'],
            '3m' => ['symbol' => '^IRX', 'source' => 'market'],
            '2y' => ['symbol' => 'DGS2', 'source' => 'fred'],
        ]);

        $provider = new class implements YieldCurveProvider
        {
            /** @var array<string, string> */
            public array $received = [];

            public function curve(array $tenors, int $days): YieldCurveData
            {
                $this->received = $tenors;

                return YieldCurveData::empty();
            }
        };

        (new YieldCurveService($provider))->curve();

        $this->assertSame(['10y' => '^TNX', '3m' => '^IRX'], $provider->received);
    }

    public function test_container_resolves_fake_provider_in_test_environment(): void
    {
        // phpunit.xml 鎖 MARKET_DATA_DRIVER=fake，故容器應綁到 Fake 實作，
        // 測試絕不打真實網路。
        $this->assertInstanceOf(
            FakeYieldCurveProvider::class,
            app(YieldCurveProvider::class),
        );

        $curve = app(YieldCurveService::class)->curve();

        $this->assertTrue($curve->hasAny());
    }
}
