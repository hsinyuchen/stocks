<?php

namespace Tests\Feature\News;

use App\Models\NewsItem;
use App\Services\News\NewsClassifier;
use App\Services\News\NewsIngestionService;
use App\Services\News\RssNewsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NewsIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A minimal RSS body whose first item links Nvidia (US tech).
     */
    private function nvidiaRss(string $url = 'https://feed-us.test/articles/1'): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <title>US Feed</title>
            <item>
              <title>Nvidia hits record high on AI demand</title>
              <link>{$url}</link>
              <description>Chip demand surges as the data center buildout accelerates.</description>
              <pubDate>Wed, 24 Jun 2026 12:00:00 +0000</pubDate>
            </item>
          </channel>
        </rss>
        XML;
    }

    private function tsmcRss(string $url = 'https://feed-tw.test/articles/9'): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <title>TW Feed</title>
            <item>
              <title>台積電擴大資本支出 加碼先進製程</title>
              <link>{$url}</link>
              <description>半導體龍頭看好人工智慧需求。</description>
              <pubDate>Wed, 24 Jun 2026 06:00:00 +0000</pubDate>
            </item>
          </channel>
        </rss>
        XML;
    }

    private function configureFeeds(array $feeds): void
    {
        config(['news.feeds' => $feeds]);
    }

    private function makeService(): NewsIngestionService
    {
        return new NewsIngestionService(new RssNewsProvider(), new NewsClassifier());
    }

    public function test_ingest_persists_classified_items(): void
    {
        $this->configureFeeds([
            ['key' => 'us', 'name' => 'US Feed', 'url' => 'https://feed-us.test/rss', 'market' => 'US', 'language' => 'en'],
            ['key' => 'tw', 'name' => 'TW Feed', 'url' => 'https://feed-tw.test/rss', 'market' => 'TW', 'language' => 'zh-TW'],
        ]);

        Http::fake([
            'feed-us.test/*' => Http::response($this->nvidiaRss(), 200),
            'feed-tw.test/*' => Http::response($this->tsmcRss(), 200),
        ]);

        $result = $this->makeService()->ingest();

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(0, $result['updated']);

        $us = NewsItem::where('source', 'US Feed')->firstOrFail();
        $this->assertSame('US', $us->market);
        $this->assertSame('tech', $us->domain);
        $this->assertContains('NVDA', $us->related_symbols);
        $this->assertNotEmpty($us->url_hash);

        $tw = NewsItem::where('source', 'TW Feed')->firstOrFail();
        $this->assertSame('TW', $tw->market);
        $this->assertSame('tech', $tw->domain);
        $this->assertContains('2330.TW', $tw->related_symbols);
    }

    public function test_ingest_twice_does_not_duplicate(): void
    {
        $this->configureFeeds([
            ['key' => 'us', 'name' => 'US Feed', 'url' => 'https://feed-us.test/rss', 'market' => 'US', 'language' => 'en'],
        ]);

        Http::fake([
            'feed-us.test/*' => Http::response($this->nvidiaRss(), 200),
        ]);

        $first = $this->makeService()->ingest();
        $second = $this->makeService()->ingest();

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, NewsItem::count());
    }

    public function test_one_failing_feed_does_not_abort_the_batch(): void
    {
        $this->configureFeeds([
            ['key' => 'bad', 'name' => 'Bad Feed', 'url' => 'https://feed-bad.test/rss', 'market' => 'US', 'language' => 'en'],
            ['key' => 'us', 'name' => 'US Feed', 'url' => 'https://feed-us.test/rss', 'market' => 'US', 'language' => 'en'],
        ]);

        Http::fake([
            'feed-bad.test/*' => Http::response('boom', 500),
            'feed-us.test/*' => Http::response($this->nvidiaRss(), 200),
        ]);

        $result = $this->makeService()->ingest();

        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, NewsItem::where('source', 'US Feed')->count());
        $this->assertSame(0, NewsItem::where('source', 'Bad Feed')->count());
    }

    public function test_prune_removes_items_older_than_retention(): void
    {
        config(['news.retention_days' => 30]);

        $stale = NewsItem::create([
            'source' => 'old',
            'title' => 'stale headline',
            'summary' => 's',
            'url' => 'https://old.test/1',
            'url_hash' => 'stale-hash',
            'published_at' => now()->subDays(45),
            'language' => 'en',
            'market' => 'US',
            'domain' => 'other',
            'related_symbols' => [],
        ]);

        $this->configureFeeds([
            ['key' => 'us', 'name' => 'US Feed', 'url' => 'https://feed-us.test/rss', 'market' => 'US', 'language' => 'en'],
        ]);

        Http::fake([
            'feed-us.test/*' => Http::response($this->nvidiaRss(), 200),
        ]);

        $result = $this->makeService()->ingest();

        $this->assertSame(1, $result['pruned']);
        $this->assertDatabaseMissing('news_items', ['id' => $stale->id]);
        $this->assertSame(1, NewsItem::where('source', 'US Feed')->count());
    }
}
