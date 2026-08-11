<?php

namespace Tests\Feature\Futures;

use App\Services\Chip\FinMindChipDataProvider;
use App\Services\Futures\FinMindFuturesDataProvider;
use App\Support\FinMindGate;
use App\Support\FinMindTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FinMind 免費層額度守門（跨 provider 熔斷）。
 */
class FinMindGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_rate_limit_response_trips_the_gate_and_short_circuits_next_calls(): void
    {
        // 免費超限：HTTP 402 + msg 含 limit。
        Http::fake([
            'api.finmindtrade.com/*' => Http::response(['msg' => 'Requests reach the maximum limit.'], 402),
        ]);

        $chip = new FinMindChipDataProvider(new FinMindTokenResolver);

        $this->assertSame([], $chip->fetch('2330.TW', 30));
        $this->assertTrue(FinMindGate::isTripped(), '撞限後應開啟冷卻。');

        // 冷卻中：第二次呼叫（即使不同 provider）直接跳過，不再發 HTTP。
        $futures = new FinMindFuturesDataProvider(new FinMindTokenResolver);
        $snapshot = $futures->snapshot();

        $this->assertFalse($snapshot->hasAny());
        // 只有第一次撞限那一發 HTTP，冷卻後不再送出。
        Http::assertSentCount(1);
    }

    public function test_paywall_response_also_trips_the_gate(): void
    {
        Http::fake([
            'api.finmindtrade.com/*' => Http::response(['msg' => 'Your level is free. Please update your user level.'], 400),
        ]);

        (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30);

        $this->assertTrue(FinMindGate::isTripped());
    }

    public function test_ordinary_failure_does_not_trip_the_gate(): void
    {
        // 500 / 逾時等一般失敗不算額度耗盡，交由各 provider 的 failure throttle 處理。
        Http::fake([
            'api.finmindtrade.com/*' => Http::response([], 500),
        ]);

        $this->assertSame([], (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30));
        $this->assertFalse(FinMindGate::isTripped(), '一般 5xx 不應開啟冷卻。');
    }

    public function test_success_response_leaves_the_gate_closed(): void
    {
        Http::fake([
            'api.finmindtrade.com/*' => Http::response(['msg' => 'success', 'data' => []], 200),
        ]);

        (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30);

        $this->assertFalse(FinMindGate::isTripped());
    }

    public function test_gate_can_be_disabled_by_config(): void
    {
        config(['finmind.gate_enabled' => false]);
        Http::fake([
            'api.finmindtrade.com/*' => Http::response(['msg' => 'limit reached'], 429),
        ]);

        (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30);

        $this->assertFalse(FinMindGate::isTripped(), '關閉守門時不得開啟冷卻。');
    }
}
