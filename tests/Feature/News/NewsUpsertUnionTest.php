<?php

namespace Tests\Feature\News;

use App\Data\NewsItemData;
use App\Models\NewsItem;
use App\Services\News\NewsIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsUpsertUnionTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $url): NewsItemData
    {
        return new NewsItemData(
            source: 'S', title: '台積電新聞', summary: '摘要',
            topic: 'stock', relatedSymbols: [],
            publishedAt: '2026-07-06T08:00:00+00:00',
            url: $url, language: 'zh-TW', market: 'TW', domain: 'tech',
        );
    }

    public function test_upsert_unions_related_symbols_across_writes(): void
    {
        $service = app(NewsIngestionService::class);
        $url = 'https://news.google.com/rss/articles/same';

        $service->upsert($this->item($url), ['2330.TW']);
        $service->upsert($this->item($url), ['2317.TW']);

        $this->assertSame(1, NewsItem::query()->count());
        $symbols = NewsItem::query()->first()->related_symbols;
        $this->assertContains('2330.TW', $symbols);
        $this->assertContains('2317.TW', $symbols);
    }

    public function test_upsert_keeps_classifier_symbols(): void
    {
        // 標題含「台積電」→ classifier 判出 2330.TW（config news.symbols 既有字典）
        $service = app(NewsIngestionService::class);

        $service->upsert($this->item('https://example.com/a'), ['NVDA']);

        $symbols = NewsItem::query()->first()->related_symbols;
        $this->assertContains('2330.TW', $symbols);
        $this->assertContains('NVDA', $symbols);
    }

    public function test_upsert_returns_true_on_insert_false_on_update(): void
    {
        $service = app(NewsIngestionService::class);
        $url = 'https://example.com/b';

        $this->assertTrue($service->upsert($this->item($url)));
        $this->assertFalse($service->upsert($this->item($url)));
    }
}
