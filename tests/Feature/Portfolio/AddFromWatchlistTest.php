<?php

namespace Tests\Feature\Portfolio;

use App\Models\Holding;
use App\Models\Instrument;
use App\Models\User;
use App\Support\MarketResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AddFromWatchlistTest extends TestCase
{
    use RefreshDatabase;

    private function watchlisted(User $user, string $symbol): Instrument
    {
        $instrument = Instrument::factory()->create(['symbol' => $symbol, 'name' => $symbol]);

        // 清單名稱對同一 user 有唯一約束，重複呼叫要沿用同一張。
        $watchlist = $user->watchlists()->firstOrCreate(['name' => 'W']);
        $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);

        return $instrument;
    }

    /**
     * currency 刻意不在 Holding 的 $fillable（由伺服端依代號判定），
     * 測試建立持倉時必須顯式賦值後再存。
     */
    private function hold(User $user, Instrument $instrument, float $shares = 1000, float $cost = 580.5): void
    {
        $holding = new Holding([
            'instrument_id' => $instrument->id,
            'shares' => $shares,
            'avg_cost' => $cost,
        ]);
        $holding->currency = MarketResolver::currency($instrument->symbol);
        $user->holdings()->save($holding);
    }

    /**
     * 自選清單頁需知道哪些標的已有持倉，才能標示狀態；否則使用者要填完股數與
     * 成本、送出後才會收到「該標的已在投資組合中」的錯誤。
     */
    public function test_watchlist_page_exposes_held_instrument_ids(): void
    {
        $user = User::factory()->create();
        $held = $this->watchlisted($user, '2330.TW');
        $notHeld = $this->watchlisted($user, '2317.TW');

        $this->hold($user, $held);

        $this->actingAs($user)
            ->get('/watchlists')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('heldInstrumentIds', [$held->id]));

        $this->assertNotContains($notHeld->id, [$held->id]);
    }

    public function test_held_ids_are_empty_when_portfolio_is_empty(): void
    {
        $user = User::factory()->create();
        $this->watchlisted($user, '2330.TW');

        $this->actingAs($user)
            ->get('/watchlists')
            ->assertInertia(fn (Assert $page) => $page->where('heldInstrumentIds', []));
    }

    /** 他人的持倉不得影響本人的標示。 */
    public function test_held_ids_ignore_other_users_holdings(): void
    {
        $user = User::factory()->create();
        $instrument = $this->watchlisted($user, '2330.TW');

        $other = User::factory()->create();
        $this->hold($other, $instrument);

        $this->actingAs($user)
            ->get('/watchlists')
            ->assertInertia(fn (Assert $page) => $page->where('heldInstrumentIds', []));
    }

    /** 自選清單的加入持倉沿用既有的 POST /portfolio，不另建平行端點。 */
    public function test_adding_from_watchlist_creates_the_holding(): void
    {
        $user = User::factory()->create();
        $instrument = $this->watchlisted($user, '2330.TW');

        $this->actingAs($user)
            ->post('/portfolio', [
                'symbol' => '2330.TW',
                'shares' => 1000,
                'avg_cost' => 580.5,
            ])
            ->assertRedirect();

        $holding = $user->holdings()->firstOrFail();

        $this->assertSame($instrument->id, $holding->instrument_id);
        $this->assertSame('TWD', $holding->currency, '幣別由伺服端依代號判定。');
    }

    public function test_duplicate_holding_is_rejected(): void
    {
        $user = User::factory()->create();
        $instrument = $this->watchlisted($user, '2330.TW');

        $this->hold($user, $instrument);

        $this->actingAs($user)
            ->post('/portfolio', ['symbol' => '2330.TW', 'shares' => 500, 'avg_cost' => 600])
            ->assertSessionHasErrors('symbol');

        $this->assertSame(1, $user->holdings()->count());
    }

    /** 持倉必須有股數與成本，否則算不出損益——不能做成一鍵加入。 */
    public function test_shares_and_cost_are_required(): void
    {
        $user = User::factory()->create();
        $this->watchlisted($user, '2330.TW');

        $this->actingAs($user)
            ->post('/portfolio', ['symbol' => '2330.TW'])
            ->assertSessionHasErrors(['shares', 'avg_cost']);
    }
}
