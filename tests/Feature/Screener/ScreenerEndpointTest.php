<?php

namespace Tests\Feature\Screener;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScreenerEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected(): void
    {
        $this->get('/screener')->assertRedirect('/login');
        $this->post('/screener/scan', ['rules' => ['above_ma20']])->assertRedirect('/login');
    }

    public function test_index_provides_rules_watchlists_and_universe_count(): void
    {
        $user = User::factory()->create();
        $user->watchlists()->create(['name' => '核心']);

        $this->actingAs($user)
            ->get('/screener')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Screener/Index')
                ->has('rules', 8)
                ->where('rules.0.key', 'kd_golden_cross')
                ->has('watchlists', 1)
                ->where('watchlists.0.name', '核心')
                ->has('universeCount'));
    }

    public function test_scan_rejects_unknown_rule_and_empty_rules(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/screener/scan', ['rules' => ['nope']])->assertStatus(422);
        $this->actingAs($user)->postJson('/screener/scan', ['rules' => []])->assertStatus(422);
    }

    public function test_scan_returns_service_shape(): void
    {
        config(['screener.universe' => [['symbol' => 'AAA', 'name' => 'Alpha']]]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/screener/scan', ['rules' => ['above_ma20']])
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('results', $response);
        $this->assertArrayHasKey('scanned', $response);
        $this->assertArrayHasKey('failures', $response);
        $this->assertArrayHasKey('skipped', $response);
    }
}
