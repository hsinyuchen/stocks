<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockChartEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function instrument(): Instrument
    {
        return Instrument::factory()->create(['symbol' => 'NVDA']);
    }

    public function test_guest_is_redirected(): void
    {
        $i = $this->instrument();

        $this->get("/stocks/{$i->id}/chart")->assertRedirect('/login');
    }

    public function test_daily_payload_shape_and_alignment(): void
    {
        $user = User::factory()->create();
        $i = $this->instrument();

        $response = $this->actingAs($user)->get("/stocks/{$i->id}/chart?tf=daily")->assertOk()->json();

        $this->assertSame('NVDA', $response['symbol']);
        $this->assertSame('daily', $response['timeframe']);
        $this->assertNotEmpty($response['candles']);

        $candle = $response['candles'][0];
        foreach (['time', 'open', 'high', 'low', 'close', 'volume'] as $key) {
            $this->assertArrayHasKey($key, $candle);
        }
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $candle['time']);

        $n = count($response['candles']);
        foreach (['ma5', 'ma20', 'ma60', 'boll_upper', 'boll_middle', 'boll_lower', 'k', 'd', 'macd', 'macd_signal', 'macd_histogram', 'rsi', 'obv'] as $key) {
            $this->assertCount($n, $response['indicators'][$key], "indicator {$key} misaligned");
        }
    }

    public function test_weekly_and_monthly_aggregate(): void
    {
        $user = User::factory()->create();
        $i = $this->instrument();

        $daily = $this->actingAs($user)->get("/stocks/{$i->id}/chart?tf=daily")->json();
        $weekly = $this->actingAs($user)->get("/stocks/{$i->id}/chart?tf=weekly")->json();
        $monthly = $this->actingAs($user)->get("/stocks/{$i->id}/chart?tf=monthly")->json();

        // 週 K 比日 K 稀疏；月 K 再比週 K 稀疏，但一個月約 4~5 週，
        // 因此週 K 數不應超過月 K 數的 5 倍（界定聚合比例，避免退化）。
        $this->assertLessThan(count($daily['candles']), count($weekly['candles']));
        $this->assertLessThan(count($monthly['candles']) * 5, count($weekly['candles']));
        $this->assertSame('weekly', $weekly['timeframe']);
        $this->assertSame('monthly', $monthly['timeframe']);
    }

    public function test_monthly_ma60_is_all_null(): void
    {
        $user = User::factory()->create();
        $i = $this->instrument();

        $monthly = $this->actingAs($user)->get("/stocks/{$i->id}/chart?tf=monthly")->json();

        $this->assertSame(
            array_fill(0, count($monthly['candles']), null),
            $monthly['indicators']['ma60'],
        );
    }

    public function test_invalid_tf_is_rejected(): void
    {
        $user = User::factory()->create();
        $i = $this->instrument();

        $this->actingAs($user)->getJson("/stocks/{$i->id}/chart?tf=hourly")->assertStatus(422);
    }
}
