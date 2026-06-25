<?php

namespace Tests\Feature\News;

use App\Models\NewsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsItemColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_news_item_persists_classification_columns(): void
    {
        $item = NewsItem::create([
            'source' => 'cnbc',
            'title' => 't',
            'summary' => 's',
            'url' => 'https://x/1',
            'url_hash' => 'h1',
            'published_at' => now(),
            'language' => 'en',
            'topic' => 'macro',
            'domain' => 'tech',
            'market' => 'US',
            'related_symbols' => ['NVDA'],
        ]);

        $this->assertDatabaseHas('news_items', [
            'url_hash' => 'h1',
            'domain' => 'tech',
            'market' => 'US',
        ]);
        $this->assertSame(['NVDA'], $item->fresh()->related_symbols);
    }
}
