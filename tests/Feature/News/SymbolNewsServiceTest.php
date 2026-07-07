<?php

namespace Tests\Feature\News;

use App\Contracts\SymbolNewsProvider;
use App\Data\NewsItemData;
use App\Models\Instrument;
use App\Models\NewsItem;
use App\Services\Fake\FakeSymbolNewsProvider;
use App\Services\News\SymbolNewsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SymbolNewsServiceTest extends TestCase
{
    use RefreshDatabase;

    /** 計數 provider stub */
    private function countingProvider(): object
    {
        return new class implements SymbolNewsProvider
        {
            public int $calls = 0;

            public function fetchForSymbol(string $symbol, string $name, ?string $market): array
            {
                $this->calls++;

                return (new FakeSymbolNewsProvider)->fetchForSymbol($symbol, $name, $market);
            }
        };
    }

    public function test_refresh_writes_items_with_symbol_tag(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '台積電']);

        app(SymbolNewsService::class)->refreshIfStale($instrument);

        $this->assertSame(2, NewsItem::query()->count());
        $this->assertContains('2330.TW', NewsItem::query()->first()->related_symbols);
    }

    public function test_freshness_gate_prevents_second_fetch_within_window(): void
    {
        $stub = $this->countingProvider();
        $this->app->instance(SymbolNewsProvider::class, $stub);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'name' => 'NVIDIA']);

        $service = app(SymbolNewsService::class);
        $service->refreshIfStale($instrument);
        $service->refreshIfStale($instrument);

        $this->assertSame(1, $stub->calls);
    }

    public function test_provider_failure_is_swallowed_and_window_holds(): void
    {
        $stub = new class implements SymbolNewsProvider
        {
            public int $calls = 0;

            public function fetchForSymbol(string $symbol, string $name, ?string $market): array
            {
                $this->calls++;
                throw new \RuntimeException('google down');
            }
        };
        $this->app->instance(SymbolNewsProvider::class, $stub);
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL', 'name' => 'Apple']);

        $service = app(SymbolNewsService::class);
        $service->refreshIfStale($instrument);   // 不拋出
        $service->refreshIfStale($instrument);   // 窗口內不重試

        $this->assertSame(1, $stub->calls);
        $this->assertSame(0, NewsItem::query()->count());
    }

    public function test_same_url_from_two_symbols_unions_related_symbols(): void
    {
        // 兩個 instrument 的 fake URL 相同時聯集——用固定 URL 的 stub
        $stub = new class implements SymbolNewsProvider
        {
            public function fetchForSymbol(string $symbol, string $name, ?string $market): array
            {
                return [new NewsItemData(
                    source: 's', title: 't', summary: 'd', topic: 'stock',
                    relatedSymbols: [strtoupper($symbol)],
                    publishedAt: '2026-06-20T08:00:00+00:00',
                    url: 'https://example.com/shared-article',
                    language: 'zh-TW', market: $market, domain: 'other',
                )];
            }
        };
        $this->app->instance(SymbolNewsProvider::class, $stub);
        $service = app(SymbolNewsService::class);

        $service->refreshIfStale(Instrument::factory()->create(['symbol' => 'AAA']));
        $service->refreshIfStale(Instrument::factory()->create(['symbol' => 'BBB']));

        $this->assertSame(1, NewsItem::query()->count());
        $symbols = NewsItem::query()->first()->related_symbols;
        $this->assertContains('AAA', $symbols);
        $this->assertContains('BBB', $symbols);
    }
}
