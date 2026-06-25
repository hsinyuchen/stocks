<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\StockAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_stock_search_to_login(): void
    {
        $this->get('/stocks/search')
            ->assertRedirect('/login');
    }

    public function test_guest_is_redirected_from_stock_analysis_action_to_login(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL']);

        $this->post("/stocks/{$instrument->id}/analyses", [
            'model' => 'reference-model',
        ])->assertRedirect('/login');
    }

    public function test_authenticated_user_can_open_stock_search_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/stocks/search')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->where('symbol', null)
                ->where('instrument', null)
                ->where('quote', null)
                ->has('prices', 0)
                ->has('news', 0)
                ->has('analyses', 0));
    }

    public function test_query_symbol_normalizes_and_creates_provider_derived_instrument_with_market_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/stocks/search?symbol=%202330.tw%20')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->where('symbol', '2330.TW')
                ->where('instrument.symbol', '2330.TW')
                ->where('instrument.market', 'TW')
                ->where('instrument.currency', 'TWD')
                ->where('quote.symbol', '2330.TW')
                ->where('quote.price', 128.5)
                ->has('prices', 20)
                ->where('news.0.title', '2330.TW 相關新聞 1')
                ->has('analyses', 0));

        $this->assertDatabaseHas('instruments', [
            'symbol' => '2330.TW',
            'name' => '2330.TW',
            'market' => 'TW',
            'asset_type' => 'stock',
            'currency' => 'TWD',
            'exchange' => null,
        ]);
    }

    public function test_search_reuses_existing_instrument_without_user_metadata_overwrite(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'market' => 'US',
            'asset_type' => 'stock',
            'currency' => 'USD',
            'exchange' => 'NASDAQ',
        ]);

        $this->actingAs($user)
            ->get('/stocks/search?symbol=aapl')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->where('symbol', 'AAPL')
                ->where('instrument.id', $instrument->id)
                ->where('instrument.name', 'Apple Inc.')
                ->where('instrument.exchange', 'NASDAQ'));

        $this->assertSame(1, Instrument::query()->where('symbol', 'AAPL')->count());
    }

    public function test_post_analyze_stores_current_user_analysis_and_hides_it_from_other_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);

        $this->actingAs($user)
            ->from('/stocks/search?symbol=NVDA')
            ->post("/stocks/{$instrument->id}/analyses", [
                'model' => 'reference-model',
            ])
            ->assertRedirect('/stocks/search?symbol=NVDA');

        $analysis = StockAnalysis::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($instrument)
            ->firstOrFail();

        $this->assertSame('fake', $analysis->provider_type);
        $this->assertSame('fake-model', $analysis->model);
        $this->assertSame('v1', $analysis->prompt_version);
        $this->assertSame('此內容僅供研究參考：目前建議維持持有或觀察，並搭配風險控管與最新資料再次確認。', $analysis->llm_output['content']);
        $this->assertArrayHasKey('stance', $analysis->rule_signal);
        $this->assertNull($analysis->technical_snapshot_id);

        $this->actingAs($user)
            ->get('/stocks/search?symbol=NVDA')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->has('analyses', 1)
                ->where('analyses.0.id', $analysis->id)
                ->where('analyses.0.llm_output.content', '此內容僅供研究參考：目前建議維持持有或觀察，並搭配風險控管與最新資料再次確認。'));

        $this->actingAs($otherUser)
            ->get('/stocks/search?symbol=NVDA')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->has('analyses', 0));
    }

    public function test_blank_or_invalid_symbol_does_not_create_instrument(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/stocks/search')
            ->get('/stocks/search?symbol=%20%20%20')
            ->assertRedirect('/stocks/search')
            ->assertInvalid(['symbol']);

        $this->actingAs($user)
            ->from('/stocks/search')
            ->get('/stocks/search?symbol=MSFT!')
            ->assertRedirect('/stocks/search')
            ->assertInvalid(['symbol']);

        $this->assertDatabaseCount('instruments', 0);
    }
}
