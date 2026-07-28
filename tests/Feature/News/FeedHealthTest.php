<?php

namespace Tests\Feature\News;

use App\Models\FeedHealth;
use App\Models\NewsItem;
use App\Services\News\NewsClassifier;
use App\Services\News\NewsIngestionService;
use App\Services\News\RssNewsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeedHealthTest extends TestCase
{
    use RefreshDatabase;

    private function rss(string $pubDate, string $url = 'https://feed-a.test/1'): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>A Feed</title>
          <item>
            <title>Nvidia chip demand surges</title>
            <link>{$url}</link>
            <description>Semiconductor outlook improves.</description>
            <pubDate>{$pubDate}</pubDate>
          </item>
        </channel></rss>
        XML;
    }

    private function service(): NewsIngestionService
    {
        return new NewsIngestionService(new RssNewsProvider, new NewsClassifier);
    }

    private function configureOneFeed(): void
    {
        config(['news.feeds' => [
            ['key' => 'a', 'name' => 'A Feed', 'url' => 'https://feed-a.test/rss', 'market' => 'US', 'language' => 'en'],
        ]]);
    }

    public function test_fresh_items_reset_the_stale_counter(): void
    {
        $this->configureOneFeed();
        Http::fake(['feed-a.test/*' => Http::response($this->rss(now()->subHours(2)->toRfc2822String()), 200)]);

        $result = $this->service()->ingest();

        $this->assertSame(1, $result['health'][0]['fresh']);
        $this->assertSame(0, $result['health'][0]['stale_runs']);
        $this->assertFalse($result['health'][0]['unhealthy']);

        $row = FeedHealth::query()->where('key', 'a')->firstOrFail();
        $this->assertNotNull($row->last_fresh_at);
    }

    /**
     * 這是本功能存在的理由：實測 WSJ Markets 回 HTTP 200、20 個項目，但最新一則
     * 是 547 天前。只看 HTTP 狀態或項目數的監控完全發現不了。
     */
    public function test_feed_returning_only_stale_items_is_detected_despite_http_200(): void
    {
        $this->configureOneFeed();
        config(['news.retention_days' => 30, 'news.health.fresh_within_days' => 7]);
        Http::fake(['feed-a.test/*' => Http::response($this->rss(now()->subDays(547)->toRfc2822String()), 200)]);

        $result = $this->service()->ingest();

        $health = $result['health'][0];

        $this->assertSame(1, $health['items'], '上游確實有回項目。');
        $this->assertSame(0, $health['fresh'], '但沒有任何一則在新鮮窗口內。');
        $this->assertSame(1, $health['stale_runs']);

        // 該則早於保留窗口，已被同一次執行的 prune 刪除。
        $this->assertSame(0, NewsItem::query()->count());
    }

    /**
     * 健康度的新鮮窗口必須與保留窗口脫鉤。
     *
     * 保留一年後，若沿用保留窗口判定，一個停止更新兩百天的 feed 仍會被算成
     * 有新鮮內容——保留窗口回答「要存多久」，健康度回答「上游還活著嗎」。
     */
    public function test_freshness_window_is_independent_of_the_retention_window(): void
    {
        $this->configureOneFeed();
        config(['news.retention_days' => 365, 'news.health.fresh_within_days' => 7]);
        Http::fake(['feed-a.test/*' => Http::response($this->rss(now()->subDays(200)->toRfc2822String()), 200)]);

        $health = $this->service()->ingest()['health'][0];

        $this->assertSame(1, $health['items']);
        $this->assertSame(0, $health['fresh'], '兩百天前的內容不算新鮮，即使仍在保留窗口內。');
        $this->assertSame(1, $health['stale_runs']);

        // 但它確實留在資料庫裡——保留一年就是要留住它。
        $this->assertSame(1, NewsItem::query()->count());
    }

    public function test_repeated_stale_runs_cross_the_unhealthy_threshold(): void
    {
        $this->configureOneFeed();
        config(['news.health.stale_runs_threshold' => 3]);
        Http::fake(['feed-a.test/*' => Http::response($this->rss(now()->subDays(400)->toRfc2822String()), 200)]);

        for ($i = 0; $i < 2; $i++) {
            $this->assertFalse($this->service()->ingest()['health'][0]['unhealthy']);
        }

        $this->assertTrue($this->service()->ingest()['health'][0]['unhealthy']);
        $this->assertSame(3, FeedHealth::query()->where('key', 'a')->firstOrFail()->consecutive_stale_runs);
    }

    /** 空回應（零項目）與陳年內容同樣要被判定為不健康——實測 Nikkei Asia 即此類。 */
    public function test_feed_returning_zero_items_counts_as_stale(): void
    {
        $this->configureOneFeed();
        Http::fake(['feed-a.test/*' => Http::response(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>A Feed</title></channel></rss>', 200
        )]);

        $health = $this->service()->ingest()['health'][0];

        $this->assertSame(0, $health['items']);
        $this->assertSame(0, $health['fresh']);
        $this->assertSame(1, $health['stale_runs']);
    }

    public function test_fetch_failure_is_recorded_with_the_error_message(): void
    {
        $this->configureOneFeed();
        Http::fake(['feed-a.test/*' => Http::response('boom', 500)]);

        $health = $this->service()->ingest()['health'][0];

        $this->assertSame(0, $health['fresh']);
        $this->assertSame(1, $health['stale_runs']);
        $this->assertNotNull(FeedHealth::query()->where('key', 'a')->firstOrFail()->last_run_at);
    }

    /** 恢復供稿後計數器必須歸零，否則一次故障會永久標記為不健康。 */
    public function test_recovery_clears_the_stale_counter(): void
    {
        $this->configureOneFeed();

        // 用 sequence 而非兩次 Http::fake()：後者不會覆蓋既有 stub，
        // 第二次抓取仍會拿到第一個註冊的回應。
        Http::fake(['feed-a.test/*' => Http::sequence()
            ->push($this->rss(now()->subDays(400)->toRfc2822String()), 200)
            ->push($this->rss(now()->subHours(1)->toRfc2822String(), 'https://feed-a.test/2'), 200),
        ]);

        $this->assertSame(1, $this->service()->ingest()['health'][0]['stale_runs']);

        $health = $this->service()->ingest()['health'][0];

        $this->assertSame(1, $health['fresh']);
        $this->assertSame(0, $health['stale_runs']);
    }
}
