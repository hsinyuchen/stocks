<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WatchlistCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_watchlists_to_login(): void
    {
        $this->get('/watchlists')
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_render_watchlists_page_with_only_own_data(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $instrument = Instrument::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'market' => 'US',
            'asset_type' => 'stock',
            'currency' => 'USD',
            'exchange' => 'NASDAQ',
        ]);
        $otherInstrument = Instrument::factory()->create([
            'symbol' => '2330.TW',
            'name' => 'Taiwan Semiconductor Manufacturing',
            'market' => 'TW',
            'asset_type' => 'stock',
            'currency' => 'TWD',
            'exchange' => 'TWSE',
        ]);

        $watchlist = Watchlist::factory()->for($user)->create(['name' => 'Core']);
        $watchlist->items()->create([
            'instrument_id' => $instrument->id,
            'sort_order' => 0,
            'note' => 'Long term',
        ]);

        $otherWatchlist = Watchlist::factory()->for($otherUser)->create(['name' => 'Other Core']);
        $otherWatchlist->items()->create([
            'instrument_id' => $otherInstrument->id,
            'sort_order' => 0,
            'note' => 'Hidden',
        ]);

        $this->actingAs($user)
            ->get('/watchlists')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Watchlists/Index')
                ->has('watchlists', 1)
                ->where('watchlists.0.id', $watchlist->id)
                ->where('watchlists.0.name', 'Core')
                ->has('watchlists.0.items', 1)
                ->where('watchlists.0.items.0.instrument.symbol', 'AAPL')
                ->where('watchlists.0.items.0.instrument.name', 'Apple Inc.')
                ->missing('watchlists.1'));
    }

    public function test_user_can_create_rename_and_delete_own_watchlist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/watchlists', ['name' => 'Momentum'])
            ->assertRedirect('/watchlists');

        $watchlist = Watchlist::query()->whereBelongsTo($user)->where('name', 'Momentum')->firstOrFail();

        $this->actingAs($user)
            ->patch("/watchlists/{$watchlist->id}", ['name' => 'Income'])
            ->assertRedirect('/watchlists');

        $this->assertDatabaseHas('watchlists', [
            'id' => $watchlist->id,
            'user_id' => $user->id,
            'name' => 'Income',
        ]);

        $this->actingAs($user)
            ->delete("/watchlists/{$watchlist->id}")
            ->assertRedirect('/watchlists');

        $this->assertDatabaseMissing('watchlists', ['id' => $watchlist->id]);
    }

    public function test_duplicate_watchlist_name_fails_for_same_user_but_is_allowed_for_different_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Watchlist::factory()->for($user)->create(['name' => 'Core']);
        Watchlist::factory()->for($otherUser)->create(['name' => 'Core']);

        $this->actingAs($user)
            ->from('/watchlists')
            ->post('/watchlists', ['name' => 'Core'])
            ->assertRedirect('/watchlists')
            ->assertInvalid(['name']);

        $this->actingAs($otherUser)
            ->from('/watchlists')
            ->post('/watchlists', ['name' => 'Growth'])
            ->assertRedirect('/watchlists')
            ->assertValid();

        $this->assertDatabaseHas('watchlists', [
            'user_id' => $otherUser->id,
            'name' => 'Growth',
        ]);
    }

    public function test_user_can_add_existing_instrument_to_watchlist_and_duplicate_item_fails_validation(): void
    {
        $user = User::factory()->create();
        $watchlist = Watchlist::factory()->for($user)->create(['name' => 'Core']);
        $existingInstrument = Instrument::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'market' => 'US',
            'asset_type' => 'stock',
            'currency' => 'USD',
            'exchange' => 'NASDAQ',
        ]);

        $this->actingAs($user)
            ->post("/watchlists/{$watchlist->id}/items", [
                'symbol' => ' aapl ',
                'name' => 'User supplied name should not overwrite',
                'market' => 'US',
                'asset_type' => 'stock',
                'currency' => 'USD',
                'exchange' => 'NYSE',
                'note' => 'Track earnings',
            ])
            // addItem 成功後改為 redirect back（無 from header 時退回站根），
            // 故此處只斷言有 302 重導，不綁定固定目標。
            ->assertRedirect();

        $this->assertDatabaseHas('watchlist_items', [
            'watchlist_id' => $watchlist->id,
            'instrument_id' => $existingInstrument->id,
            'note' => 'Track earnings',
        ]);
        $this->assertDatabaseHas('instruments', [
            'id' => $existingInstrument->id,
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'exchange' => 'NASDAQ',
        ]);

        $this->assertSame(1, $watchlist->items()->count());

        $this->actingAs($user)
            ->from('/watchlists')
            ->post("/watchlists/{$watchlist->id}/items", [
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'market' => 'US',
                'asset_type' => 'stock',
                'currency' => 'USD',
                'exchange' => 'NASDAQ',
            ])
            ->assertRedirect('/watchlists')
            ->assertInvalid(['symbol']);
    }

    public function test_adding_a_new_symbol_creates_the_instrument_with_name_and_market_then_links_it(): void
    {
        $user = User::factory()->create();
        $watchlist = Watchlist::factory()->for($user)->create(['name' => 'Core']);

        $this->actingAs($user)
            ->from('/watchlists')
            ->post("/watchlists/{$watchlist->id}/items", [
                'symbol' => 'msft',
                'name' => 'Microsoft Corporation',
                'market' => 'US',
                'note' => 'Cloud + AI',
            ])
            ->assertRedirect('/watchlists')
            ->assertValid();

        $this->assertDatabaseHas('instruments', [
            'symbol' => 'MSFT',
            'name' => 'Microsoft Corporation',
            'market' => 'US',
            'asset_type' => 'stock',
            'currency' => 'USD',
            'exchange' => null,
        ]);

        $instrument = Instrument::query()->where('symbol', 'MSFT')->firstOrFail();
        $this->assertDatabaseHas('watchlist_items', [
            'watchlist_id' => $watchlist->id,
            'instrument_id' => $instrument->id,
            'note' => 'Cloud + AI',
        ]);
        $this->assertSame(1, $watchlist->items()->count());
    }

    public function test_adding_a_new_taiwan_symbol_infers_tw_market_and_currency_from_suffix(): void
    {
        $user = User::factory()->create();
        $watchlist = Watchlist::factory()->for($user)->create(['name' => 'Core']);

        $this->actingAs($user)
            ->from('/watchlists')
            ->post("/watchlists/{$watchlist->id}/items", [
                'symbol' => '2330.tw',
                'name' => '台積電',
            ])
            ->assertRedirect('/watchlists')
            ->assertValid();

        $this->assertDatabaseHas('instruments', [
            'symbol' => '2330.TW',
            'name' => '台積電',
            'market' => 'TW',
            'asset_type' => 'stock',
            'currency' => 'TWD',
            'exchange' => null,
        ]);

        $instrument = Instrument::query()->where('symbol', '2330.TW')->firstOrFail();
        $this->assertSame('TW', $instrument->market->value);
    }

    public function test_adding_a_taiwan_etf_records_it_as_an_etf_not_a_stock(): void
    {
        /*
         * 這個建列點原本硬寫 asset_type = 'stock'。台股 ETF 的代號規則判得出來
         * （`00` 開頭，個股從 1101 起跳，兩者不衝突），標錯的代價不只在資料層：
         * LongTermHealthReader 會照個股走完四塊判定，roe 為 null 落到
         * 「資料還沒累積到可判定的量，再跑幾次就會有」——ETF 永遠不會有 ROE。
         * 沒有這條測試，改回硬寫不會有任何訊號。
         */
        $user = User::factory()->create();
        $watchlist = Watchlist::factory()->for($user)->create(['name' => 'Core']);

        $this->actingAs($user)
            ->from('/watchlists')
            ->post("/watchlists/{$watchlist->id}/items", [
                'symbol' => '0050.tw',
                'name' => '元大台灣50',
            ])
            ->assertRedirect('/watchlists')
            ->assertValid();

        $this->assertDatabaseHas('instruments', [
            'symbol' => '0050.TW',
            'market' => 'TW',
            'asset_type' => 'etf',
            'currency' => 'TWD',
        ]);
    }

    public function test_adding_a_known_us_etf_records_it_as_an_etf(): void
    {
        // 美股沒有代號規則可判，靠 MarketResolver 的清單（刻意不完整，見其 docblock）。
        // 清單命中時至少要標對——這條同時釘住「建列點有走 resolver」。
        $user = User::factory()->create();
        $watchlist = Watchlist::factory()->for($user)->create(['name' => 'Core']);

        $this->actingAs($user)
            ->from('/watchlists')
            ->post("/watchlists/{$watchlist->id}/items", [
                'symbol' => 'qqq',
                'name' => 'Invesco QQQ Trust',
            ])
            ->assertRedirect('/watchlists')
            ->assertValid();

        $this->assertDatabaseHas('instruments', [
            'symbol' => 'QQQ',
            'market' => 'US',
            'asset_type' => 'etf',
            'currency' => 'USD',
        ]);
    }

    public function test_adding_a_new_symbol_without_a_name_falls_back_to_the_symbol(): void
    {
        $user = User::factory()->create();
        $watchlist = Watchlist::factory()->for($user)->create(['name' => 'Core']);

        $this->actingAs($user)
            ->from('/watchlists')
            ->post("/watchlists/{$watchlist->id}/items", [
                'symbol' => 'tsla',
            ])
            ->assertRedirect('/watchlists')
            ->assertValid();

        $this->assertDatabaseHas('instruments', [
            'symbol' => 'TSLA',
            'name' => 'TSLA',
            'market' => 'US',
        ]);
    }

    public function test_user_cannot_update_delete_another_users_watchlist_or_add_or_remove_another_users_item(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownWatchlist = Watchlist::factory()->for($user)->create(['name' => 'Mine']);
        $otherWatchlist = Watchlist::factory()->for($otherUser)->create(['name' => 'Theirs']);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);
        $otherItem = $otherWatchlist->items()->create([
            'instrument_id' => $instrument->id,
            'sort_order' => 0,
        ]);

        $this->actingAs($user)
            ->patch("/watchlists/{$otherWatchlist->id}", ['name' => 'Stolen'])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete("/watchlists/{$otherWatchlist->id}")
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/watchlists/{$otherWatchlist->id}/items", [
                'symbol' => 'NVDA',
                'name' => 'Nvidia',
                'market' => 'US',
                'asset_type' => 'stock',
                'currency' => 'USD',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete("/watchlists/{$ownWatchlist->id}/items/{$otherItem->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('watchlists', [
            'id' => $otherWatchlist->id,
            'name' => 'Theirs',
            'user_id' => $otherUser->id,
        ]);
        $this->assertDatabaseHas('watchlist_items', ['id' => $otherItem->id]);
    }
}
