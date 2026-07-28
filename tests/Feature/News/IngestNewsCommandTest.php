<?php

namespace Tests\Feature\News;

use App\Models\NewsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestNewsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function nvidiaRss(string $url = 'https://feed-us.test/articles/1'): string
    {
        // 發布時間相對於「現在」：ingest() 會依 news.retention_days 立刻 prune，
        // 寫死日期會在超出保留窗口後讓測試集體失敗。
        $pubDate = now()->subHours(2)->toRfc2822String();

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <title>US Feed</title>
            <item>
              <title>Nvidia hits record high on AI demand</title>
              <link>{$url}</link>
              <description>Chip demand surges as the data center buildout accelerates.</description>
              <pubDate>{$pubDate}</pubDate>
            </item>
          </channel>
        </rss>
        XML;
    }

    public function test_command_runs_ingestion_and_persists_items(): void
    {
        config(['news.feeds' => [
            ['key' => 'us', 'name' => 'US Feed', 'url' => 'https://feed-us.test/rss', 'market' => 'US', 'language' => 'en'],
        ]]);

        Http::fake([
            'feed-us.test/*' => Http::response($this->nvidiaRss(), 200),
        ]);

        $this->artisan('news:ingest')->assertSuccessful();

        $this->assertSame(1, NewsItem::count());

        $item = NewsItem::firstOrFail();
        $this->assertSame('US Feed', $item->source);
        $this->assertSame('US', $item->market);
        $this->assertSame('tech', $item->domain);
        $this->assertContains('NVDA', $item->related_symbols);
    }
}
