<?php

namespace Tests\Feature\Search;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function yahooPayload(): array
    {
        return [
            'quotes' => [
                ['symbol' => 'NVDA', 'shortname' => 'NVIDIA Corporation', 'quoteType' => 'EQUITY', 'exchange' => 'NMS'],
            ],
        ];
    }

    private function finmindPayload(): array
    {
        return [
            'msg' => 'success',
            'data' => [
                ['stock_id' => '2330', 'stock_name' => '台積電', 'type' => 'twse', 'date' => '2026-06-27'],
            ],
        ];
    }

    public function test_guest_is_redirected_from_lookup_to_login(): void
    {
        $this->get('/stocks/lookup?q=NVDA&market=us')
            ->assertRedirect('/login');
    }

    public function test_authenticated_us_lookup_returns_results_from_yahoo(): void
    {
        Http::fake(['*query2.finance.yahoo.com*' => Http::response($this->yahooPayload(), 200)]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/stocks/lookup?q=NVDA&market=us');

        $response->assertOk()
            ->assertJsonPath('results.0.symbol', 'NVDA')
            ->assertJsonPath('results.0.market', 'US');

        $this->assertNotEmpty($response->json('results'));
    }

    public function test_authenticated_tw_lookup_returns_results_from_finmind(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->finmindPayload(), 200)]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/stocks/lookup?q=台積電&market=tw');

        $response->assertOk()
            ->assertJsonPath('results.0.symbol', '2330.TW')
            ->assertJsonPath('results.0.name', '台積電')
            ->assertJsonPath('results.0.market', 'TW');

        $this->assertNotEmpty($response->json('results'));
    }

    public function test_missing_market_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/stocks/lookup?q=NVDA')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['market']);
    }

    public function test_invalid_market_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/stocks/lookup?q=NVDA&market=jp')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['market']);
    }

    public function test_empty_query_returns_empty_results_without_error(): void
    {
        Http::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/stocks/lookup?market=us')
            ->assertOk()
            ->assertExactJson(['results' => []]);

        Http::assertNothingSent();
    }

    public function test_upstream_failure_yields_empty_results_not_500(): void
    {
        Http::fake(['*query2.finance.yahoo.com*' => Http::response('', 500)]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/stocks/lookup?q=NVDA&market=us')
            ->assertOk()
            ->assertExactJson(['results' => []]);
    }
}
