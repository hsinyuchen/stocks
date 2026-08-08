<?php

namespace Tests\Feature\Alerts;

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuoteData;
use App\Models\Alert;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AlertEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function alertFor(User $user, string $symbol = 'NVDA'): Alert
    {
        $instrument = Instrument::factory()->create(['symbol' => $symbol, 'name' => $symbol]);
        $alert = new Alert(['instrument_id' => $instrument->id, 'type' => 'price_above', 'threshold' => 100]);
        $user->alerts()->save($alert);

        return $alert;
    }

    /** 不炸的 quote stub，避免 index 觸發 evaluate 時打真 provider。 */
    private function bindQuietProvider(): void
    {
        $this->app->bind(MarketDataProvider::class, fn () => new class implements MarketDataProvider
        {
            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 1.0, 0.0, 0.0, '2026-07-08T00:00:00+00:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                return [];
            }
        });
    }

    public function test_guest_is_redirected(): void
    {
        $this->get('/alerts')->assertRedirect('/login');
        $this->post('/alerts', [])->assertRedirect('/login');
    }

    public function test_index_renders_grouped_and_triggers_evaluation(): void
    {
        // 綁一個會讓 price_above(threshold 100) 命中的 provider
        $this->app->bind(MarketDataProvider::class, fn () => new class implements MarketDataProvider
        {
            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 150.0, 0.0, 0.0, '2026-07-08T00:00:00+00:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                return [];
            }
        });

        $user = User::factory()->create();
        $alert = $this->alertFor($user);

        $this->actingAs($user)
            ->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Alerts/Index')
                ->has('active')
                ->has('triggered', 1)
                ->has('signalRules'));

        // index 進頁觸發了 evaluate
        $this->assertSame('triggered', $alert->refresh()->status);
    }

    public function test_store_price_alert(): void
    {
        $this->bindQuietProvider();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/alerts', [
            'symbol' => '  nvda ',
            'type' => 'price_above',
            'threshold' => 123.45,
        ])->assertRedirect();

        $alert = $user->alerts()->firstOrFail();
        $this->assertSame('NVDA', $alert->instrument->symbol);
        $this->assertSame('price_above', $alert->type);
        $this->assertSame('123.4500', $alert->threshold);
        $this->assertSame($user->id, $alert->user_id);
    }

    public function test_store_signal_alert(): void
    {
        $this->bindQuietProvider();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/alerts', [
            'symbol' => 'NVDA',
            'type' => 'signal',
            'signal_key' => 'kd_golden_cross',
        ])->assertRedirect();

        $this->assertSame('kd_golden_cross', $user->alerts()->firstOrFail()->signal_key);
    }

    public function test_store_market_futures_flip_alert(): void
    {
        $this->bindQuietProvider();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/alerts', [
            'type' => 'market_futures_flip',
            'note' => '大盤轉空提示',
        ])->assertRedirect();

        $alert = $user->alerts()->firstOrFail();
        $this->assertSame('market_futures_flip', $alert->type);
        $this->assertNull($alert->instrument_id);
        $this->assertNull($alert->threshold);
        $this->assertNull($alert->signal_key);
        $this->assertSame('大盤轉空提示', $alert->note);
    }

    public function test_store_market_bearish_flip_alert(): void
    {
        $this->bindQuietProvider();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/alerts', ['type' => 'market_bearish_flip'])->assertRedirect();

        $alert = $user->alerts()->firstOrFail();
        $this->assertSame('market_bearish_flip', $alert->type);
        $this->assertNull($alert->instrument_id);
    }

    public function test_market_alert_prohibits_symbol_and_threshold(): void
    {
        $this->bindQuietProvider();
        $user = User::factory()->create();
        $post = fn (array $data) => $this->actingAs($user)->from('/alerts')->post('/alerts', $data);

        $post(['type' => 'market_futures_flip', 'symbol' => 'NVDA'])->assertSessionHasErrors('symbol');
        $post(['type' => 'market_futures_flip', 'threshold' => 5])->assertSessionHasErrors('threshold');

        $this->assertSame(0, $user->alerts()->count());
    }

    public function test_duplicate_active_market_alert_rejected(): void
    {
        $this->bindQuietProvider();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/alerts', ['type' => 'market_futures_flip'])->assertRedirect();
        $this->actingAs($user)->from('/alerts')->post('/alerts', ['type' => 'market_futures_flip'])
            ->assertSessionHasErrors('type');

        $this->assertSame(1, $user->alerts()->count());
    }

    public function test_store_validation(): void
    {
        $this->bindQuietProvider();
        $user = User::factory()->create();
        $post = fn (array $data) => $this->actingAs($user)->from('/alerts')->post('/alerts', $data);

        // 訊號類帶 threshold → 互斥拒絕
        $post(['symbol' => 'NVDA', 'type' => 'signal', 'signal_key' => 'kd_golden_cross', 'threshold' => 5])
            ->assertSessionHasErrors('threshold');
        // 價格類帶 signal_key → 互斥拒絕
        $post(['symbol' => 'NVDA', 'type' => 'price_above', 'threshold' => 5, 'signal_key' => 'kd_golden_cross'])
            ->assertSessionHasErrors('signal_key');
        // 非法 signal_key
        $post(['symbol' => 'NVDA', 'type' => 'signal', 'signal_key' => 'nope'])
            ->assertSessionHasErrors('signal_key');
        // 價格類負門檻
        $post(['symbol' => 'NVDA', 'type' => 'price_above', 'threshold' => -5])
            ->assertSessionHasErrors('threshold');
        // 指數被拒
        $post(['symbol' => '^TWII', 'type' => 'price_above', 'threshold' => 5])
            ->assertSessionHasErrors('symbol');
        // INF threshold
        $post(['symbol' => 'NVDA', 'type' => 'price_above', 'threshold' => '1e400'])
            ->assertSessionHasErrors('threshold');
        // change_pct 負無窮門檻（下界防護）
        $post(['symbol' => 'NVDA', 'type' => 'change_pct_below', 'threshold' => '-1e999'])
            ->assertSessionHasErrors('threshold');

        $this->assertSame(0, $user->alerts()->count());
    }

    public function test_change_pct_below_allows_negative_threshold(): void
    {
        $this->bindQuietProvider();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/alerts', [
            'symbol' => 'NVDA', 'type' => 'change_pct_below', 'threshold' => -3.5,
        ])->assertRedirect();

        $this->assertSame('-3.5000', $user->alerts()->firstOrFail()->threshold);
    }

    public function test_reactivate_and_destroy(): void
    {
        $this->bindQuietProvider();
        $user = User::factory()->create();
        $alert = $this->alertFor($user);
        $alert->forceFill(['status' => 'triggered', 'triggered_at' => now(), 'triggered_price' => 150])->save();

        $this->actingAs($user)->patch("/alerts/{$alert->id}/reactivate")->assertRedirect();
        $alert->refresh();
        $this->assertSame('active', $alert->status);
        $this->assertNull($alert->triggered_at);
        $this->assertNull($alert->triggered_price);

        $this->actingAs($user)->delete("/alerts/{$alert->id}")->assertRedirect();
        $this->assertSame(0, $user->alerts()->count());
    }

    public function test_cannot_touch_another_users_alert(): void
    {
        $this->bindQuietProvider();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $alert = $this->alertFor($other);

        $this->actingAs($user)->patch("/alerts/{$alert->id}/reactivate")->assertForbidden();
        $this->actingAs($user)->delete("/alerts/{$alert->id}")->assertForbidden();

        $this->actingAs($user)
            ->get('/alerts')
            ->assertInertia(fn (Assert $page) => $page->has('active', 0)->has('triggered', 0));
    }
}
