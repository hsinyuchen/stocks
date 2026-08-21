<?php

namespace Tests\Feature\Rates;

use App\Contracts\YieldCurveProvider;
use App\Data\YieldCurveData;
use App\Models\User;
use App\Services\Market\MarketBreadthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RatesPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_breadth_snapshot_carries_rates_block(): void
    {
        // fake driver 的曲線為牛陡、未倒掛。
        $snapshot = app(MarketBreadthService::class)->snapshot();

        $this->assertArrayHasKey('rates', $snapshot);
        $this->assertTrue($snapshot['rates']['available']);
        $this->assertFalse($snapshot['rates']['inverted']);
        $this->assertSame('bull_steepening', $snapshot['rates']['windows']['20d']['quadrant']);
        $this->assertGreaterThan(0, $snapshot['rates']['spread_bp']);
        // breadth snapshot 全站共用快取，不得挾帶語系相依內容：只斷言 key，
        // 顯示文字交由前端 i18n 解析（見 finding #2）。
        $this->assertSame('10y', $snapshot['rates']['long_tenor']);
    }

    public function test_rates_block_reports_unavailable_without_faking_values(): void
    {
        $this->app->bind(YieldCurveProvider::class, fn () => new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                return YieldCurveData::empty();
            }
        });

        $snapshot = app(MarketBreadthService::class)->snapshot();

        $this->assertFalse($snapshot['rates']['available']);
        // 無資料一律 null，不得以 0 或舊值冒充。
        $this->assertNull($snapshot['rates']['spread_bp']);
        $this->assertNull($snapshot['rates']['long_yield']);
        $this->assertSame([], $snapshot['rates']['windows']);
    }

    public function test_rates_failure_does_not_break_the_other_breadth_blocks(): void
    {
        $this->app->bind(YieldCurveProvider::class, fn () => new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                return YieldCurveData::empty();
            }
        });

        $snapshot = app(MarketBreadthService::class)->snapshot();

        $this->assertTrue($snapshot['institutional']['available']);
        $this->assertTrue($snapshot['futures']['available']);
    }

    public function test_dashboard_page_carries_rates_via_deferred_prop(): void
    {
        $user = User::factory()->create();

        // marketBreadth 為 deferred prop，需以 partial 請求解析（見 TestCase::getDashboard）。
        $props = $this->actingAs($user)->getDashboard()->assertOk()->json('props');

        $this->assertArrayHasKey('rates', $props['marketBreadth']);
        $this->assertTrue($props['marketBreadth']['rates']['available']);
    }
}
