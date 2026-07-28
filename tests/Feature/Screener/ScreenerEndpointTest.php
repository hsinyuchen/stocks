<?php

namespace Tests\Feature\Screener;

use App\Models\Instrument;
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
                // 8 條技術面 + 3 條籌碼面 + 3 條基本面
                ->has('rules', 14)
                ->where('rules.0.key', 'kd_golden_cross')
                ->has('watchlists', 1)
                ->where('watchlists.0.name', '核心')
                ->has('universeCount'));
    }

    /** /screener 的 watchlists prop 只能有本人的清單。 */
    public function test_index_does_not_expose_other_users_watchlists(): void
    {
        $user = User::factory()->create();
        $user->watchlists()->create(['name' => '我的清單']);

        $other = User::factory()->create();
        $other->watchlists()->create(['name' => '別人的清單']);

        $this->actingAs($user)
            ->get('/screener')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('watchlists', 1)
                ->where('watchlists.0.name', '我的清單'));
    }

    /** 掃描結果可反推自選股內容，故 endpoint 層也需鎖定隔離，不只服務層。 */
    public function test_scan_does_not_include_other_users_watchlist_symbols(): void
    {
        config(['screener.universe' => []]);

        $user = User::factory()->create();
        $other = User::factory()->create();

        $otherInstrument = Instrument::factory()->create(['symbol' => 'THEIRS.TW']);
        $otherWatchlist = $other->watchlists()->create(['name' => '別人的清單']);
        $otherWatchlist->items()->create(['instrument_id' => $otherInstrument->id, 'sort_order' => 0]);

        $response = $this->actingAs($user)
            ->postJson('/screener/scan', ['rules' => ['above_ma20']])
            ->assertOk()
            ->json();

        $this->assertSame(0, $response['scanned']);
        $this->assertStringNotContainsString('THEIRS.TW', json_encode($response, JSON_THROW_ON_ERROR));
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

    /** 顯示的可掃描檔數須等於「內建股池 ∪ 自選股」去重後的數量。 */
    public function test_pool_count_reflects_universe_union_watchlists(): void
    {
        config(['screener.universe' => [
            ['symbol' => 'AAA', 'name' => 'Alpha'],
            ['symbol' => 'BBB', 'name' => 'Beta'],
        ]]);

        $user = User::factory()->create();
        $watchlist = $user->watchlists()->create(['name' => 'W']);

        // 一檔與股池重複（只算一次）、一檔是新的。
        foreach ([['AAA', 'Alpha'], ['CCC', 'Gamma']] as [$symbol, $name]) {
            $instrument = Instrument::factory()->create(['symbol' => $symbol, 'name' => $name]);
            $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);
        }

        $this->actingAs($user)
            ->get('/screener')
            ->assertInertia(fn (Assert $page) => $page
                ->where('universeCount', 2)
                ->where('poolCount', 3));
    }

    /** 他人的自選股不得灌大本人的可掃描檔數。 */
    public function test_pool_count_ignores_other_users_watchlists(): void
    {
        config(['screener.universe' => [['symbol' => 'AAA', 'name' => 'Alpha']]]);

        $other = User::factory()->create();
        $otherList = $other->watchlists()->create(['name' => 'W']);
        $instrument = Instrument::factory()->create(['symbol' => 'THEIRS.TW']);
        $otherList->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);

        $this->actingAs(User::factory()->create())
            ->get('/screener')
            ->assertInertia(fn (Assert $page) => $page->where('poolCount', 1));
    }
}
