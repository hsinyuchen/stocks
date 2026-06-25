<?php

namespace Tests\Feature\News;

use App\Data\NewsItemData;
use App\Models\NewsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsItemKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_news_item_persists_video_kind(): void
    {
        $item = NewsItem::create([
            'source' => 'CNBC',
            'title' => 't',
            'summary' => 's',
            'url' => 'https://youtube.com/watch?v=1',
            'url_hash' => 'h1',
            'published_at' => now(),
            'language' => 'en',
            'topic' => 'macro',
            'domain' => 'tech',
            'market' => 'US',
            'kind' => 'video',
            'related_symbols' => ['NVDA'],
        ]);

        $this->assertDatabaseHas('news_items', [
            'url_hash' => 'h1',
            'kind' => 'video',
        ]);
        $this->assertSame('video', $item->fresh()->kind);
    }

    public function test_news_item_kind_defaults_to_article(): void
    {
        $item = NewsItem::create([
            'source' => 'cnbc',
            'title' => 't',
            'summary' => 's',
            'url' => 'https://x/2',
            'url_hash' => 'h2',
            'published_at' => now(),
        ]);

        $this->assertSame('article', $item->fresh()->kind);
    }

    public function test_news_item_data_defaults_kind_to_article(): void
    {
        $data = new NewsItemData(
            source: 'cnbc',
            title: 't',
            summary: 's',
            topic: 'macro',
            relatedSymbols: [],
            publishedAt: '2026-06-20T00:00:00+00:00',
        );

        $this->assertSame('article', $data->kind);
    }

    public function test_news_item_data_accepts_video_kind(): void
    {
        $data = new NewsItemData(
            source: 'CNBC',
            title: 't',
            summary: 's',
            topic: 'macro',
            relatedSymbols: [],
            publishedAt: '2026-06-20T00:00:00+00:00',
            kind: 'video',
        );

        $this->assertSame('video', $data->kind);
    }
}
