<?php

namespace Tests\Feature\Market;

use App\Models\User;
use App\Services\Market\FinMindMarketInstitutionalProvider;
use App\Services\Market\MarketBreadthService;
use App\Support\FinMindGate;
use App\Support\FinMindTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketBreadthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_finmind_provider_nets_foreign_trust_dealer(): void
    {
        Http::fake([
            'api.finmindtrade.com/*' => Http::response(['msg' => 'success', 'data' => [
                ['date' => '2026-08-07', 'name' => 'Foreign_Investor', 'buy' => 300, 'sell' => 350],
                ['date' => '2026-08-07', 'name' => 'Foreign_Dealer_Self', 'buy' => 10, 'sell' => 5],
                ['date' => '2026-08-07', 'name' => 'Investment_Trust', 'buy' => 20, 'sell' => 8],
                ['date' => '2026-08-07', 'name' => 'Dealer_self', 'buy' => 9, 'sell' => 8],
                ['date' => '2026-08-07', 'name' => 'Dealer_Hedging', 'buy' => 24, 'sell' => 26],
                ['date' => '2026-08-07', 'name' => 'total', 'buy' => 363, 'sell' => 397],
            ]], 200),
        ]);

        $data = (new FinMindMarketInstitutionalProvider(new FinMindTokenResolver))->latest();

        $this->assertSame('2026-08-07', $data->date);
        // 外資 = (300-350) + (10-5) = -45；投信 = 12；自營 = (9-8)+(24-26) = -1。
        $this->assertSame(-45, $data->foreignNet);
        $this->assertSame(12, $data->trustNet);
        $this->assertSame(-1, $data->dealerNet);
        $this->assertTrue($data->hasAny());
    }

    public function test_foreign_net_series_builds_ascending_history(): void
    {
        Http::fake([
            'api.finmindtrade.com/*' => Http::response(['msg' => 'success', 'data' => [
                // 亂序、含他法人；只取外資（含外資自營）逐日 buy−sell，依日期升冪。
                ['date' => '2026-08-06', 'name' => 'Foreign_Investor', 'buy' => 100, 'sell' => 300],
                ['date' => '2026-08-06', 'name' => 'Foreign_Dealer_Self', 'buy' => 10, 'sell' => 20],
                ['date' => '2026-08-04', 'name' => 'Foreign_Investor', 'buy' => 200, 'sell' => 250],
                ['date' => '2026-08-05', 'name' => 'Foreign_Investor', 'buy' => 150, 'sell' => 400],
                ['date' => '2026-08-06', 'name' => 'Investment_Trust', 'buy' => 999, 'sell' => 0],
            ]], 200),
        ]);

        $series = (new FinMindMarketInstitutionalProvider(new FinMindTokenResolver))->foreignNetSeries(3);

        $this->assertSame([
            ['date' => '2026-08-04', 'net' => -50],   // 200-250
            ['date' => '2026-08-05', 'net' => -250],  // 150-400
            ['date' => '2026-08-06', 'net' => -210],  // (100-300)+(10-20)
        ], $series);
    }

    public function test_provider_is_best_effort_on_failure(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response([], 500)]);

        $data = (new FinMindMarketInstitutionalProvider(new FinMindTokenResolver))->latest();

        $this->assertFalse($data->hasAny());
    }

    public function test_provider_short_circuits_when_gate_tripped(): void
    {
        FinMindGate::trip();
        Http::fake(['api.finmindtrade.com/*' => Http::response(['data' => []], 200)]);

        $data = (new FinMindMarketInstitutionalProvider(new FinMindTokenResolver))->latest();

        $this->assertFalse($data->hasAny());
        Http::assertNothingSent();
    }

    public function test_breadth_service_combines_institutional_and_futures(): void
    {
        // fake driver：走 FakeMarketInstitutionalProvider + FakeFuturesDataProvider，不打網路。
        $snapshot = app(MarketBreadthService::class)->snapshot();

        $this->assertTrue($snapshot['institutional']['available']);
        $this->assertSame(-40_715_743_790, $snapshot['institutional']['foreign_net']);

        $this->assertTrue($snapshot['futures']['available']);
        $this->assertSame(-8000, $snapshot['futures']['foreign_net_oi']);
        $this->assertSame(1.1, $snapshot['futures']['put_call_ratio']);
    }

    public function test_dashboard_page_carries_market_breadth(): void
    {
        $user = User::factory()->create();

        // marketBreadth 為 deferred prop，需以 partial 請求解析。
        $props = $this->actingAs($user)->getDashboard()->assertOk()->json('props');

        $this->assertArrayHasKey('marketBreadth', $props);
        $this->assertTrue($props['marketBreadth']['institutional']['available']);
        $this->assertTrue($props['marketBreadth']['futures']['available']);
    }
}
