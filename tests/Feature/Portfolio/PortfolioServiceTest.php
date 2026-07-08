<?php

namespace Tests\Feature\Portfolio;

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuoteData;
use App\Models\Holding;
use App\Models\Instrument;
use App\Models\User;
use App\Services\PortfolioService;
use App\Support\MarketResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * per-symbol 價格 + 可對指定 symbol 拋例外的 stub。
     * 不可用 FakeMarketDataProvider：它對任何 symbol 恆回 128.50 且永不拋例外，
     * 測不出 per-symbol 損益差異與 unavailable 分支。
     *
     * @param  array<string, float>  $prices
     * @param  list<string>  $failing
     */
    private function bindProvider(array $prices, array $failing = []): void
    {
        $this->app->bind(MarketDataProvider::class, fn () => new class($prices, $failing) implements MarketDataProvider
        {
            /**
             * @param  array<string, float>  $prices
             * @param  list<string>  $failing
             */
            public function __construct(private readonly array $prices, private readonly array $failing) {}

            public function quote(string $symbol): MarketQuoteData
            {
                if (in_array($symbol, $this->failing, true)) {
                    throw new \RuntimeException('quote unavailable');
                }

                return new MarketQuoteData($symbol, $this->prices[$symbol], 0.0, 0.0, '2026-07-08T01:00:00+00:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                return [];
            }
        });
    }

    private function holding(User $user, string $symbol, float $shares, float $avgCost): Holding
    {
        $instrument = Instrument::factory()->create(['symbol' => $symbol, 'name' => $symbol]);
        $holding = new Holding(['instrument_id' => $instrument->id, 'shares' => $shares, 'avg_cost' => $avgCost]);
        $holding->currency = MarketResolver::currency($symbol);
        $user->holdings()->save($holding);

        return $holding;
    }

    public function test_computes_market_value_pnl_and_return_pct(): void
    {
        $this->bindProvider(['2330.TW' => 1050.0]);
        $user = User::factory()->create();
        $this->holding($user, '2330.TW', 1000.0, 900.0);

        $summary = app(PortfolioService::class)->summary($user);
        $row = $summary['groups'][0]['holdings'][0];

        $this->assertSame(1050.0, $row['price']);
        $this->assertSame(900000.0, $row['cost_basis']);
        $this->assertSame(1050000.0, $row['market_value']);
        $this->assertSame(150000.0, $row['unrealized_pnl']);
        $this->assertSame(16.67, $row['return_pct']);
        $this->assertSame('2026-07-08T01:00:00+00:00', $row['as_of']);
    }

    public function test_return_pct_is_null_when_cost_basis_is_zero(): void
    {
        // 贈與股：avg_cost = 0 → 除零，報酬率必須為 null 而非 INF
        $this->bindProvider(['NVDA' => 100.0]);
        $user = User::factory()->create();
        $this->holding($user, 'NVDA', 10.0, 0.0);

        $row = app(PortfolioService::class)->summary($user)['groups'][0]['holdings'][0];

        $this->assertSame(0.0, $row['cost_basis']);
        $this->assertSame(1000.0, $row['market_value']);
        $this->assertNull($row['return_pct']);
    }

    public function test_groups_by_currency_with_subtotals(): void
    {
        $this->bindProvider(['2330.TW' => 1000.0, 'NVDA' => 200.0]);
        $user = User::factory()->create();
        $this->holding($user, '2330.TW', 10.0, 900.0);   // TWD: cost 9000, mv 10000
        $this->holding($user, 'NVDA', 5.0, 100.0);        // USD: cost 500, mv 1000

        $groups = app(PortfolioService::class)->summary($user)['groups'];

        $this->assertCount(2, $groups);
        $this->assertSame('TWD', $groups[0]['currency']);
        $this->assertSame('USD', $groups[1]['currency']);
        $this->assertSame(10000.0, $groups[0]['subtotal']['market_value']);
        $this->assertSame(9000.0, $groups[0]['subtotal']['cost_basis']);
        $this->assertSame(1000.0, $groups[0]['subtotal']['unrealized_pnl']);
        $this->assertSame(11.11, $groups[0]['subtotal']['return_pct']);
        $this->assertSame(500.0, $groups[1]['subtotal']['unrealized_pnl']);
        $this->assertSame(100.0, $groups[1]['subtotal']['return_pct']);
    }

    public function test_quote_failure_excludes_holding_from_subtotal_and_is_reported(): void
    {
        $this->bindProvider(['GOOD.TW' => 100.0], failing: ['BAD.TW']);
        $user = User::factory()->create();
        $this->holding($user, 'GOOD.TW', 10.0, 50.0);   // cost 500, mv 1000
        $this->holding($user, 'BAD.TW', 10.0, 50.0);    // 無報價

        $summary = app(PortfolioService::class)->summary($user);
        $rows = collect($summary['groups'][0]['holdings']);
        $bad = $rows->firstWhere('symbol', 'BAD.TW');

        $this->assertNull($bad['price']);
        $this->assertNull($bad['market_value']);
        $this->assertNull($bad['unrealized_pnl']);
        $this->assertNull($bad['return_pct']);
        $this->assertSame(500.0, $bad['cost_basis']);   // 成本仍顯示

        // 無報價者不得以成本價冒充市值：小計只含 GOOD.TW
        $this->assertSame(1000.0, $summary['groups'][0]['subtotal']['market_value']);
        $this->assertSame(500.0, $summary['groups'][0]['subtotal']['cost_basis']);

        $this->assertCount(1, $summary['unavailable']);
        $this->assertSame('BAD.TW', $summary['unavailable'][0]['symbol']);
    }

    public function test_numeric_payload_fields_are_floats_not_strings(): void
    {
        // decimal cast 回傳 string；service 必須 (float) 轉型後才輸出
        $this->bindProvider(['NVDA' => 200.0]);
        $user = User::factory()->create();
        $this->holding($user, 'NVDA', 5.0, 100.0);

        $row = app(PortfolioService::class)->summary($user)['groups'][0]['holdings'][0];

        $this->assertIsFloat($row['shares']);
        $this->assertIsFloat($row['avg_cost']);
        $this->assertIsFloat($row['cost_basis']);
    }

    public function test_empty_portfolio_returns_no_groups(): void
    {
        $this->bindProvider([]);

        $summary = app(PortfolioService::class)->summary(User::factory()->create());

        $this->assertSame([], $summary['groups']);
        $this->assertSame([], $summary['unavailable']);
    }
}
