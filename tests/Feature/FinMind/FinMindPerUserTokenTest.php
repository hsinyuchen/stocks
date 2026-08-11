<?php

namespace Tests\Feature\FinMind;

use App\Models\FinMindSetting;
use App\Models\User;
use App\Services\Market\FinMindMarketDataProvider;
use App\Support\FinMindGate;
use App\Support\FinMindTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinMindPerUserTokenTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): FinMindTokenResolver
    {
        return app(FinMindTokenResolver::class);
    }

    public function test_resolver_falls_back_to_global_token_and_honours_overrides(): void
    {
        config(['services.finmind.token' => 'GLOBAL']);
        $resolver = $this->resolver();

        $this->assertSame('GLOBAL', $resolver->resolve());

        $resolver->useToken('USER-TOKEN');
        $this->assertSame('USER-TOKEN', $resolver->resolve());

        // 空字串視為清除 → 退回全站。
        $resolver->useToken('');
        $this->assertSame('GLOBAL', $resolver->resolve());

        $resolver->useToken('AGAIN');
        $resolver->reset();
        $this->assertSame('GLOBAL', $resolver->resolve());
    }

    public function test_resolver_reads_the_users_encrypted_token(): void
    {
        config(['services.finmind.token' => 'GLOBAL']);
        $resolver = $this->resolver();

        $withToken = User::factory()->create();
        $withToken->finmindSetting()->create(['token_encrypted' => 'HERS']);

        $withoutToken = User::factory()->create();

        $resolver->useUserToken($withToken);
        $this->assertSame('HERS', $resolver->resolve());

        // 沒設定 → 退回全站；null user（未登入）亦然。
        $resolver->useUserToken($withoutToken);
        $this->assertSame('GLOBAL', $resolver->resolve());

        $resolver->useUserToken(null);
        $this->assertSame('GLOBAL', $resolver->resolve());
    }

    public function test_gate_cooldown_is_per_token(): void
    {
        config(['finmind.gate_enabled' => true]);
        $resolver = $this->resolver();

        $resolver->useToken('TOKEN-A');
        FinMindGate::trip();
        $this->assertTrue(FinMindGate::isTripped());

        // 換一組 token → 不受 A 的冷卻影響。
        $resolver->useToken('TOKEN-B');
        $this->assertFalse(FinMindGate::isTripped());

        // 切回 A → 仍在冷卻。
        $resolver->useToken('TOKEN-A');
        $this->assertTrue(FinMindGate::isTripped());
    }

    public function test_provider_sends_the_currently_resolved_token(): void
    {
        Http::fake([
            'api.finmindtrade.com/*' => Http::response([
                'data' => [
                    ['date' => '2026-08-01', 'open' => 1, 'max' => 2, 'min' => 1, 'close' => 1.5, 'Trading_Volume' => 1000],
                    ['date' => '2026-08-02', 'open' => 1, 'max' => 2, 'min' => 1, 'close' => 1.6, 'Trading_Volume' => 1000],
                ],
            ], 200),
        ]);

        $resolver = $this->resolver();
        $resolver->useToken('PER-USER-TOKEN');

        $provider = new FinMindMarketDataProvider($resolver);
        $provider->dailyPrices('2330.TW', 2);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'token=PER-USER-TOKEN'));
    }

    public function test_store_encrypts_the_token_and_reports_has_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/finmind', ['token' => 'secret-token'])
            ->assertRedirect(route('settings.index'));

        $setting = $user->finmindSetting()->first();
        $this->assertNotNull($setting);
        // cast 讀取為明文，資料庫落地為密文。
        $this->assertSame('secret-token', $setting->token_encrypted);
        $this->assertNotSame('secret-token', $setting->getRawOriginal('token_encrypted'));

        $props = $this->actingAs($user)->get('/settings')->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['finmind']['has_token']);
    }

    public function test_store_updates_the_same_single_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/finmind', ['token' => 'first']);
        $this->actingAs($user)->post('/settings/finmind', ['token' => 'second']);

        $this->assertSame(1, FinMindSetting::query()->where('user_id', $user->id)->count());
        $this->assertSame('second', $user->finmindSetting()->first()->token_encrypted);
    }

    public function test_destroy_clears_only_the_current_users_token(): void
    {
        $alice = User::factory()->create();
        $alice->finmindSetting()->create(['token_encrypted' => 'ALICE']);
        $bob = User::factory()->create();
        $bob->finmindSetting()->create(['token_encrypted' => 'BOB']);

        $this->actingAs($alice)
            ->delete('/settings/finmind')
            ->assertRedirect(route('settings.index'));

        $this->assertNull($alice->finmindSetting()->first());
        // 不得動到別人的設定。
        $this->assertSame('BOB', $bob->finmindSetting()->first()->token_encrypted);
    }

    public function test_finmind_settings_require_authentication(): void
    {
        $this->post('/settings/finmind', ['token' => 'x'])->assertRedirect('/login');
        $this->delete('/settings/finmind')->assertRedirect('/login');
    }
}
