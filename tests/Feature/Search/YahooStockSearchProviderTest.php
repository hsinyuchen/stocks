<?php

namespace Tests\Feature\Search;

use App\Services\Search\YahooStockSearchProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YahooStockSearchProviderTest extends TestCase
{
    private function payload(): array
    {
        return [
            'quotes' => [
                // US EQUITY on NMS — kept.
                ['symbol' => 'NVDA', 'shortname' => 'NVIDIA Corporation', 'longname' => 'NVIDIA Corporation', 'quoteType' => 'EQUITY', 'exchange' => 'NMS'],
                // US ETF on PCX — kept.
                ['symbol' => 'SPY', 'shortname' => 'SPDR S&P 500 ETF Trust', 'quoteType' => 'ETF', 'exchange' => 'PCX'],
                // Taiwan listing — dropped (foreign exchange).
                ['symbol' => '2330.TW', 'shortname' => 'Taiwan Semiconductor', 'quoteType' => 'EQUITY', 'exchange' => 'TAI'],
                // Foreign exchange — dropped.
                ['symbol' => 'NESN.SW', 'shortname' => 'Nestle SA', 'quoteType' => 'EQUITY', 'exchange' => 'EBS'],
                // Non equity/etf quoteType — dropped.
                ['symbol' => 'BTCUSD', 'shortname' => 'Bitcoin USD', 'quoteType' => 'CRYPTOCURRENCY', 'exchange' => 'CCC'],
            ],
        ];
    }

    public function test_search_returns_us_quotes_and_excludes_foreign_entries(): void
    {
        Http::fake(['*query2.finance.yahoo.com*' => Http::response($this->payload(), 200)]);

        $results = (new YahooStockSearchProvider)->search('Nvidia');

        $this->assertContains(
            ['symbol' => 'NVDA', 'name' => 'NVIDIA Corporation', 'market' => 'US', 'exchange' => 'NMS'],
            $results,
        );

        $symbols = array_column($results, 'symbol');
        $this->assertContains('SPY', $symbols);
        $this->assertNotContains('2330.TW', $symbols);
        $this->assertNotContains('NESN.SW', $symbols);
        $this->assertNotContains('BTCUSD', $symbols);

        foreach ($results as $row) {
            $this->assertSame('US', $row['market']);
        }
    }

    public function test_name_falls_back_to_longname_then_symbol(): void
    {
        Http::fake(['*query2.finance.yahoo.com*' => Http::response([
            'quotes' => [
                ['symbol' => 'LNG', 'longname' => 'Cheniere Energy Inc', 'quoteType' => 'EQUITY', 'exchange' => 'ASE'],
                ['symbol' => 'NONM', 'quoteType' => 'EQUITY', 'exchange' => 'NYQ'],
            ],
        ], 200)]);

        $results = (new YahooStockSearchProvider)->search('energy');

        $byName = array_column($results, 'name', 'symbol');
        $this->assertSame('Cheniere Energy Inc', $byName['LNG']);
        $this->assertSame('NONM', $byName['NONM']);
    }

    public function test_empty_or_whitespace_query_returns_empty_without_upstream_call(): void
    {
        Http::fake(['*query2.finance.yahoo.com*' => Http::response($this->payload(), 200)]);

        $this->assertSame([], (new YahooStockSearchProvider)->search(''));
        $this->assertSame([], (new YahooStockSearchProvider)->search('   '));

        Http::assertNothingSent();
    }

    public function test_sends_browser_user_agent_and_query(): void
    {
        Http::fake(['*query2.finance.yahoo.com*' => Http::response($this->payload(), 200)]);

        (new YahooStockSearchProvider)->search('Nvidia');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'query2.finance.yahoo.com/v1/finance/search')
            && str_contains($request->url(), 'q=Nvidia')
            && str_contains($request->header('User-Agent')[0] ?? '', 'StockRadar/1.0'));
    }

    public function test_upstream_failure_returns_empty(): void
    {
        Http::fake(['*query2.finance.yahoo.com*' => Http::response('', 500)]);

        $this->assertSame([], (new YahooStockSearchProvider)->search('Nvidia'));
    }

    public function test_results_are_capped_at_fifteen(): void
    {
        $quotes = [];
        for ($i = 0; $i < 30; $i++) {
            $quotes[] = ['symbol' => "SYM{$i}", 'shortname' => "Company {$i}", 'quoteType' => 'EQUITY', 'exchange' => 'NMS'];
        }
        Http::fake(['*query2.finance.yahoo.com*' => Http::response(['quotes' => $quotes], 200)]);

        $results = (new YahooStockSearchProvider)->search('Company');

        $this->assertCount(15, $results);
    }
}
