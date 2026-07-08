<?php

namespace Tests\Feature\Portfolio;

use App\Models\Holding;
use App\Models\Instrument;
use App\Models\User;
use App\Support\MarketResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortfolioEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function holdingFor(User $user, string $symbol): Holding
    {
        $instrument = Instrument::factory()->create(['symbol' => $symbol, 'name' => $symbol]);
        $holding = new Holding(['instrument_id' => $instrument->id, 'shares' => 10, 'avg_cost' => 100]);
        $holding->currency = MarketResolver::currency($symbol);
        $user->holdings()->save($holding);

        return $holding;
    }

    public function test_guest_is_redirected(): void
    {
        $this->get('/portfolio')->assertRedirect('/login');
        $this->post('/portfolio', [])->assertRedirect('/login');
    }

    public function test_index_renders_grouped_payload(): void
    {
        $user = User::factory()->create();
        $this->holdingFor($user, '2330.TW');

        $this->actingAs($user)
            ->get('/portfolio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolio/Index')
                ->has('groups', 1)
                ->where('groups.0.currency', 'TWD')
                ->has('groups.0.holdings', 1)
                ->has('groups.0.subtotal')
                ->has('unavailable'));
    }

    /** 持倉表格直接渲染備註欄，payload 必須帶 note。 */
    public function test_index_payload_exposes_holding_note(): void
    {
        $user = User::factory()->create();
        $holding = $this->holdingFor($user, '2330.TW');
        $holding->update(['note' => '長期持有']);

        $this->actingAs($user)
            ->get('/portfolio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.0.holdings.0.note', '長期持有'));
    }

    public function test_store_creates_holding_with_server_derived_currency(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/portfolio', [
            'symbol' => 'NVDA',
            'shares' => 5,
            'avg_cost' => 100.5,
            'currency' => 'JPY',      // 惡意輸入，必須被忽略
        ])->assertRedirect();

        $holding = $user->holdings()->firstOrFail();
        $this->assertSame('USD', $holding->currency);
        $this->assertSame('NVDA', $holding->instrument->symbol);
        $this->assertSame('100.5000', $holding->avg_cost);
    }

    public function test_store_normalizes_lowercase_symbol(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/portfolio', [
            'symbol' => '  nvda ',
            'shares' => 1,
            'avg_cost' => 1,
        ])->assertRedirect();

        $this->assertSame('NVDA', $user->holdings()->firstOrFail()->instrument->symbol);
    }

    public function test_store_rejects_duplicate_symbol(): void
    {
        $user = User::factory()->create();
        $this->holdingFor($user, 'NVDA');

        $this->actingAs($user)
            ->from('/portfolio')
            ->post('/portfolio', ['symbol' => 'NVDA', 'shares' => 1, 'avg_cost' => 1])
            ->assertSessionHasErrors('symbol');

        $this->assertSame(1, $user->holdings()->count());
    }

    public function test_store_rejects_invalid_input(): void
    {
        $user = User::factory()->create();

        // shares 必須 > 0
        $this->actingAs($user)->from('/portfolio')
            ->post('/portfolio', ['symbol' => 'NVDA', 'shares' => 0, 'avg_cost' => 1])
            ->assertSessionHasErrors('shares');

        // 指數不可持有
        $this->actingAs($user)->from('/portfolio')
            ->post('/portfolio', ['symbol' => '^TWII', 'shares' => 1, 'avg_cost' => 1])
            ->assertSessionHasErrors('symbol');

        $this->assertSame(0, $user->holdings()->count());
    }

    /**
     * `1e400` 溢出成 float INF，能通過 numeric + min。若寫進 decimal(20,4)，
     * 之後每次讀取都會在 decimal cast 拋 MathException，投資組合頁永久 500。
     */
    public function test_store_rejects_non_finite_amounts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/portfolio')
            ->post('/portfolio', ['symbol' => 'NVDA', 'shares' => '1e400', 'avg_cost' => 1])
            ->assertSessionHasErrors('shares');

        $this->actingAs($user)->from('/portfolio')
            ->post('/portfolio', ['symbol' => 'NVDA', 'shares' => 1, 'avg_cost' => '1e400'])
            ->assertSessionHasErrors('avg_cost');

        $this->assertSame(0, $user->holdings()->count());
    }

    public function test_update_rejects_non_finite_amounts(): void
    {
        $user = User::factory()->create();
        $holding = $this->holdingFor($user, 'NVDA');

        $this->actingAs($user)->from('/portfolio')
            ->patch("/portfolio/{$holding->id}", ['shares' => '1e400', 'avg_cost' => 1])
            ->assertSessionHasErrors('shares');

        $this->actingAs($user)->from('/portfolio')
            ->patch("/portfolio/{$holding->id}", ['shares' => 1, 'avg_cost' => '1e400'])
            ->assertSessionHasErrors('avg_cost');

        $holding->refresh();
        $this->assertSame('10.0000', $holding->shares);
        $this->assertSame('100.0000', $holding->avg_cost);

        // index 仍可正常渲染（未被 INF 汙染）
        $this->actingAs($user)->get('/portfolio')->assertOk();
    }

    public function test_update_and_destroy(): void
    {
        $user = User::factory()->create();
        $holding = $this->holdingFor($user, 'NVDA');

        $this->actingAs($user)
            ->patch("/portfolio/{$holding->id}", ['shares' => 20, 'avg_cost' => 150, 'note' => '加碼'])
            ->assertRedirect();

        $holding->refresh();
        $this->assertSame('20.0000', $holding->shares);
        $this->assertSame('150.0000', $holding->avg_cost);
        $this->assertSame('加碼', $holding->note);

        $this->actingAs($user)->delete("/portfolio/{$holding->id}")->assertRedirect();
        $this->assertSame(0, $user->holdings()->count());
    }

    public function test_cannot_touch_another_users_holding(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $holding = $this->holdingFor($other, 'NVDA');

        $this->actingAs($user)->patch("/portfolio/{$holding->id}", ['shares' => 1, 'avg_cost' => 1])->assertForbidden();
        $this->actingAs($user)->delete("/portfolio/{$holding->id}")->assertForbidden();

        $this->actingAs($user)
            ->get('/portfolio')
            ->assertInertia(fn (Assert $page) => $page->has('groups', 0));

        $this->assertSame(1, $other->holdings()->count());
    }
}
