<?php

namespace Tests\Feature;

use App\Contracts\SymbolNewsProvider;
use App\Models\Instrument;
use App\Models\StockAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
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
                ->missing('prices')
                ->missing('indicators')
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
                // 首載瘦身：prices/indicators 不再由 index 輸出，前端掛載後另打 chart endpoint。
                ->missing('prices')
                ->missing('indicators')
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

    public function test_query_name_param_is_stored_as_the_created_instrument_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/stocks/search?symbol=2330.TW&name='.urlencode('台積電'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->where('symbol', '2330.TW')
                ->where('instrument.symbol', '2330.TW')
                ->where('instrument.name', '台積電'));

        $this->assertDatabaseHas('instruments', [
            'symbol' => '2330.TW',
            'name' => '台積電',
            'market' => 'TW',
            'currency' => 'TWD',
        ]);
    }

    public function test_query_name_does_not_overwrite_existing_instrument_name(): void
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
            ->get('/stocks/search?symbol=AAPL&name=Different Name')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->where('instrument.id', $instrument->id)
                ->where('instrument.name', 'Apple Inc.'));

        $this->assertSame(1, Instrument::query()->where('symbol', 'AAPL')->count());
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

        // 未設定 AI 模型：規則訊號照常保存，LLM 區塊必須誠實標示未設定，
        // 不得以假內容冒充 AI 分析。
        $this->assertSame('none', $analysis->provider_type);
        $this->assertSame('reference-model', $analysis->model);
        $this->assertSame('v1', $analysis->prompt_version);
        $this->assertStringContainsString('尚未設定 AI 模型', $analysis->llm_output['content']);
        $this->assertArrayHasKey('stance', $analysis->rule_signal);
        $this->assertNull($analysis->technical_snapshot_id);

        $this->actingAs($user)
            ->get('/stocks/search?symbol=NVDA')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->has('analyses', 1)
                ->where('analyses.0.id', $analysis->id)
                ->where('analyses.0.llm_output.provider', 'none'));

        $this->actingAs($otherUser)
            ->get('/stocks/search?symbol=NVDA')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->has('analyses', 0));
    }

    public function test_analyze_uses_selected_provider_and_stores_real_output(): void
    {
        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response([
                'model' => 'llama3.1',
                'choices' => [['message' => ['content' => '參考分析：留意均線糾結。']]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $setting = $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Local Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);

        $this->actingAs($user)
            ->from('/stocks/search?symbol=NVDA')
            ->post("/stocks/{$instrument->id}/analyses", [
                'llm_provider_setting_id' => $setting->id,
            ])
            ->assertRedirect('/stocks/search?symbol=NVDA');

        $analysis = StockAnalysis::query()->whereBelongsTo($user)->firstOrFail();
        $this->assertSame('ollama', $analysis->provider_type);
        $this->assertSame('llama3.1', $analysis->model);
        $this->assertStringContainsString('參考分析', $analysis->llm_output['content']);

        Http::assertSent(fn ($request) => $request->url() === 'http://localhost:11434/v1/chat/completions');
    }

    public function test_analyze_degrades_when_selected_provider_errors(): void
    {
        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response(['error' => 'down'], 500),
        ]);

        $user = User::factory()->create();
        $setting = $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Local Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);

        $this->actingAs($user)
            ->from('/stocks/search?symbol=NVDA')
            ->post("/stocks/{$instrument->id}/analyses", ['llm_provider_setting_id' => $setting->id])
            ->assertRedirect('/stocks/search?symbol=NVDA');

        $analysis = StockAnalysis::query()->whereBelongsTo($user)->firstOrFail();
        $this->assertSame('error', $analysis->provider_type);
        $this->assertStringContainsString('AI 分析暫時無法使用', $analysis->llm_output['content']);
    }

    public function test_cannot_analyze_with_another_users_provider_setting(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherSetting = $other->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Other Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);

        $this->actingAs($user)
            ->post("/stocks/{$instrument->id}/analyses", ['llm_provider_setting_id' => $otherSetting->id])
            ->assertForbidden();
    }

    public function test_search_page_exposes_user_llm_providers(): void
    {
        $user = User::factory()->create();
        $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Local Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);

        $this->actingAs($user)
            ->get('/stocks/search')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Stocks/Search')
                ->has('llmProviders', 1)
                ->where('llmProviders.0.display_name', 'Local Ollama')
                ->where('llmProviders.0.model', 'llama3.1'));
    }

    public function test_stock_page_triggers_symbol_news_refresh(): void
    {
        $counting = new class implements SymbolNewsProvider
        {
            public int $calls = 0;

            public function fetchForSymbol(string $symbol, string $name, ?string $market): array
            {
                $this->calls++;

                return [];
            }
        };
        $this->app->instance(SymbolNewsProvider::class, $counting);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/stocks/search?symbol=NVDA')->assertOk();

        $this->assertSame(1, $counting->calls);
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

    public function test_taiwan_stock_page_includes_fundamentals(): void
    {
        // fake driver 綁 FakeFundamentalsProvider（回固定值）
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/stocks/search?symbol=2330.TW')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Stocks/Search')
                ->where('fundamentals.per', 18.5)
                ->where('fundamentals.eps', 8.4));
    }

    public function test_us_stock_page_has_null_fundamentals(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/stocks/search?symbol=NVDA')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fundamentals', null));
    }
}
