<?php

namespace Tests\Feature\News;

use App\Services\News\GoogleNewsSymbolNewsProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleNewsSymbolNewsProviderTest extends TestCase
{
    private const RSS = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Google News</title>
<item><title>t</title><link>https://news.google.com/rss/articles/x</link>
<pubDate>Mon, 06 Jul 2026 08:00:00 GMT</pubDate><description>d</description>
<source url="https://a.b">媒體A</source></item>
</channel></rss>
XML;

    public function test_taiwan_symbol_queries_chinese_name_with_tw_params(): void
    {
        Http::fake(['news.google.com/*' => Http::response(self::RSS)]);

        $items = app(GoogleNewsSymbolNewsProvider::class)->fetchForSymbol('2330.TW', '台積電', 'TW');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'hl=zh-TW')
                && str_contains($request->url(), 'gl=TW')
                && str_contains($request->url(), rawurlencode('台積電'));
        });
        $this->assertCount(1, $items);
        $this->assertSame('媒體A', $items[0]->source);
        $this->assertContains('2330.TW', [$items[0]->relatedSymbols[0] ?? null] ?: []);
    }

    public function test_us_symbol_queries_name_stock_with_us_params(): void
    {
        Http::fake(['news.google.com/*' => Http::response(self::RSS)]);

        app(GoogleNewsSymbolNewsProvider::class)->fetchForSymbol('NVDA', 'NVIDIA', 'US');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'hl=en-US')
            && str_contains($request->url(), rawurlencode('"NVIDIA" stock')));
    }

    public function test_taiwan_fallback_strips_tw_suffix_when_name_is_symbol(): void
    {
        Http::fake(['news.google.com/*' => Http::response(self::RSS)]);

        app(GoogleNewsSymbolNewsProvider::class)->fetchForSymbol('2330.TW', '2330.TW', 'TW');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'q=2330&')
            || str_ends_with(parse_url($request->url(), PHP_URL_QUERY) ?? '', 'q=2330'));
    }
}
