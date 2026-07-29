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
                ->has('instrumentCount'));
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

    /**
     * 掃描範圍是全站標的清單，與誰把它加進自選無關。
     *
     * 標的清單是公開的標的資料，不含使用者資訊；真正需要隔離的是「哪些是我的
     * 自選」，由 poolBreakdown 的 in_watchlist 負責（見 ScreenerServiceTest）。
     */
    public function test_scan_covers_the_shared_instrument_list(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $otherInstrument = Instrument::factory()->create(['symbol' => 'THEIRS.TW']);
        $other->watchlists()->create(['name' => '別人的清單'])
            ->items()->create(['instrument_id' => $otherInstrument->id, 'sort_order' => 0]);

        $response = $this->actingAs($user)
            ->postJson('/screener/scan', ['rules' => ['above_ma20']])
            ->assertOk()
            ->json();

        $this->assertSame(1, $response['scanned']);
    }

    public function test_scan_rejects_unknown_rule_and_empty_rules(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/screener/scan', ['rules' => ['nope']])->assertStatus(422);
        $this->actingAs($user)->postJson('/screener/scan', ['rules' => []])->assertStatus(422);
    }

    public function test_scan_returns_service_shape(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha']);
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

    /** 顯示的可掃描檔數須等於「標的清單 ∪ 自選股」去重後的數量。 */
    public function test_pool_count_reflects_instrument_list_union_watchlists(): void
    {
        $instruments = collect([['AAA', 'Alpha'], ['BBB', 'Beta'], ['CCC', 'Gamma']])
            ->mapWithKeys(fn (array $row) => [
                $row[0] => Instrument::factory()->create(['symbol' => $row[0], 'name' => $row[1]]),
            ]);

        $user = User::factory()->create();
        $watchlist = $user->watchlists()->create(['name' => 'W']);

        // 自選的兩檔都已在標的清單上，去重後不會讓 poolCount 變大。
        foreach (['AAA', 'CCC'] as $symbol) {
            $watchlist->items()->create(['instrument_id' => $instruments[$symbol]->id, 'sort_order' => 0]);
        }

        $this->actingAs($user)
            ->get('/screener')
            ->assertInertia(fn (Assert $page) => $page
                // 標的清單共 3 檔（AAA/BBB/CCC），自選 2 檔全在其中，去重後仍是 3。
                ->where('instrumentCount', 3)
                ->where('poolCount', 3)
                ->where('watchlistCount', 2));
    }

    /** 他人的自選不得算進「我的自選股」檔數。 */
    public function test_watchlist_count_ignores_other_users_watchlists(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha']);

        $other = User::factory()->create();
        $otherList = $other->watchlists()->create(['name' => 'W']);
        $instrument = Instrument::factory()->create(['symbol' => 'THEIRS.TW']);
        $otherList->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);

        $this->actingAs(User::factory()->create())
            ->get('/screener')
            ->assertInertia(fn (Assert $page) => $page
                // 兩檔都在標的清單上，所以都掃得到……
                ->where('poolCount', 2)
                // ……但沒有一檔是我的自選。
                ->where('watchlistCount', 0));
    }
}
