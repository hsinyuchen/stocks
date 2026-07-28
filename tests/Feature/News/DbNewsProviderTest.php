<?php

namespace Tests\Feature\News;

use App\Data\NewsItemData;
use App\Models\NewsItem;
use App\Services\News\DbNewsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbNewsProviderTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(array $overrides = []): NewsItem
    {
        return NewsItem::create(array_merge([
            'source' => 'CNBC',
            'title' => 'headline',
            'summary' => 'summary',
            'url' => 'https://news.test/'.uniqid(),
            'url_hash' => sha1(uniqid('', true)),
            'published_at' => now(),
            'language' => 'en',
            'market' => 'US',
            'topic' => 'macro',
            'domain' => 'tech',
            'related_symbols' => ['NVDA'],
        ], $overrides));
    }

    public function test_latest_market_news_returns_empty_for_non_positive_limit(): void
    {
        $this->makeItem();

        $provider = new DbNewsProvider;

        $this->assertSame([], $provider->latestMarketNews('US', 0));
        $this->assertSame([], $provider->latestMarketNews('US', -1));
    }

    public function test_latest_market_news_filters_by_market_newest_first(): void
    {
        $this->makeItem(['source' => 'US Old', 'market' => 'US', 'published_at' => now()->subDays(2)]);
        $this->makeItem(['source' => 'US New', 'market' => 'US', 'published_at' => now()->subDay()]);
        $this->makeItem(['source' => 'TW One', 'market' => 'TW', 'published_at' => now()]);

        $provider = new DbNewsProvider;

        $items = $provider->latestMarketNews('US', 10);

        $this->assertCount(2, $items);
        $this->assertContainsOnlyInstancesOf(NewsItemData::class, $items);
        $this->assertSame('US New', $items[0]->source);
        $this->assertSame('US Old', $items[1]->source);
        $this->assertSame('US', $items[0]->market);
    }

    public function test_latest_market_news_returns_all_markets_when_market_blank(): void
    {
        $this->makeItem(['market' => 'US']);
        $this->makeItem(['market' => 'TW']);

        $provider = new DbNewsProvider;

        $this->assertCount(2, $provider->latestMarketNews('', 10));
    }

    public function test_latest_market_news_respects_limit(): void
    {
        $this->makeItem(['market' => 'US', 'published_at' => now()->subDays(3)]);
        $this->makeItem(['market' => 'US', 'published_at' => now()->subDays(2)]);
        $this->makeItem(['market' => 'US', 'published_at' => now()->subDay()]);

        $provider = new DbNewsProvider;

        $this->assertCount(2, $provider->latestMarketNews('US', 2));
    }

    public function test_related_news_returns_empty_for_non_positive_limit(): void
    {
        $this->makeItem(['related_symbols' => ['NVDA']]);

        $provider = new DbNewsProvider;

        $this->assertSame([], $provider->relatedNews('NVDA', 0));
        $this->assertSame([], $provider->relatedNews('NVDA', -1));
    }

    public function test_related_news_matches_symbol_in_json_newest_first(): void
    {
        $this->makeItem(['source' => 'NVDA Old', 'related_symbols' => ['NVDA'], 'published_at' => now()->subDays(2)]);
        $this->makeItem(['source' => 'NVDA New', 'related_symbols' => ['NVDA', 'AMD'], 'published_at' => now()->subDay()]);
        $this->makeItem(['source' => 'AAPL Only', 'related_symbols' => ['AAPL'], 'published_at' => now()]);

        $provider = new DbNewsProvider;

        $items = $provider->relatedNews('NVDA', 10);

        $this->assertCount(2, $items);
        $this->assertContainsOnlyInstancesOf(NewsItemData::class, $items);
        $this->assertSame('NVDA New', $items[0]->source);
        $this->assertSame('NVDA Old', $items[1]->source);
        $this->assertContains('NVDA', $items[0]->relatedSymbols);
    }

    public function test_related_news_respects_limit(): void
    {
        $this->makeItem(['related_symbols' => ['NVDA'], 'published_at' => now()->subDays(3)]);
        $this->makeItem(['related_symbols' => ['NVDA'], 'published_at' => now()->subDays(2)]);
        $this->makeItem(['related_symbols' => ['NVDA'], 'published_at' => now()->subDay()]);

        $provider = new DbNewsProvider;

        $this->assertCount(2, $provider->relatedNews('NVDA', 2));
    }
}
