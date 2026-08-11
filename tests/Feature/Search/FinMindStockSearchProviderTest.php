<?php

namespace Tests\Feature\Search;

use App\Services\Search\FinMindStockSearchProvider;
use App\Support\FinMindTokenResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinMindStockSearchProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function payload(): array
    {
        return [
            'msg' => 'success',
            'data' => [
                // twse 2330, duplicated across industry rows to exercise dedupe.
                ['industry_category' => 'Semiconductor', 'stock_id' => '2330', 'stock_name' => '台積電', 'type' => 'twse', 'date' => '2026-06-27'],
                ['industry_category' => '半導體業', 'stock_id' => '2330', 'stock_name' => '台積電', 'type' => 'twse', 'date' => '2026-06-27'],
                // tpex listing maps to .TWO.
                ['industry_category' => '生技醫療業', 'stock_id' => '6446', 'stock_name' => '藥華藥', 'type' => 'tpex', 'date' => '2026-06-27'],
                ['industry_category' => 'Optoelectronic', 'stock_id' => '2454', 'stock_name' => '聯發科', 'type' => 'twse', 'date' => '2026-06-27'],
            ],
        ];
    }

    public function test_search_by_chinese_name_returns_mapped_twse_symbol(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        $results = (new FinMindStockSearchProvider(new FinMindTokenResolver))->search('台積電');

        $this->assertContains(
            ['symbol' => '2330.TW', 'name' => '台積電', 'market' => 'TW'],
            $results,
        );
    }

    public function test_search_by_code_returns_matching_symbol(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        $results = (new FinMindStockSearchProvider(new FinMindTokenResolver))->search('2330');

        $symbols = array_column($results, 'symbol');
        $this->assertContains('2330.TW', $symbols);
    }

    public function test_tpex_stock_maps_to_two_suffix(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        $results = (new FinMindStockSearchProvider(new FinMindTokenResolver))->search('6446');

        $this->assertContains(
            ['symbol' => '6446.TWO', 'name' => '藥華藥', 'market' => 'TW'],
            $results,
        );
    }

    public function test_duplicate_rows_dedupe_to_one_entry_per_code(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        $results = (new FinMindStockSearchProvider(new FinMindTokenResolver))->search('2330');

        $matching = array_filter($results, fn ($row) => $row['symbol'] === '2330.TW');
        $this->assertCount(1, $matching);
    }

    public function test_empty_or_whitespace_query_returns_empty_without_upstream_call(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        $this->assertSame([], (new FinMindStockSearchProvider(new FinMindTokenResolver))->search(''));
        $this->assertSame([], (new FinMindStockSearchProvider(new FinMindTokenResolver))->search('   '));

        Http::assertNothingSent();
    }

    public function test_token_is_sent_when_configured(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        $resolver = new FinMindTokenResolver;
        $resolver->useToken('finmind-token-xyz');
        (new FinMindStockSearchProvider($resolver))->search('台積電');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'dataset=TaiwanStockInfo')
            && str_contains($request->url(), 'token=finmind-token-xyz'));
    }

    public function test_upstream_failure_returns_empty_and_is_not_cached(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response('', 500)]);

        $this->assertSame([], (new FinMindStockSearchProvider(new FinMindTokenResolver))->search('台積電'));
        $this->assertNull(Cache::get('stock:search:tw-universe'));
    }

    public function test_cached_universe_is_a_plain_array(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        (new FinMindStockSearchProvider(new FinMindTokenResolver))->search('台積電');

        $cached = Cache::get('stock:search:tw-universe');
        $this->assertIsArray($cached);
        foreach ($cached as $row) {
            $this->assertIsArray($row);
        }
    }

    public function test_results_are_capped_at_fifteen(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $code = (string) (1000 + $i);
            $rows[] = ['stock_id' => $code, 'stock_name' => "公司{$code}", 'type' => 'twse', 'date' => '2026-06-27'];
        }
        Http::fake(['*api.finmindtrade.com*' => Http::response(['msg' => 'success', 'data' => $rows], 200)]);

        $results = (new FinMindStockSearchProvider(new FinMindTokenResolver))->search('公司');

        $this->assertCount(15, $results);
    }
}
