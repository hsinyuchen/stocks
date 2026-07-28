<?php

namespace Tests\Feature\News;

use App\Data\NewsItemData;
use App\Models\FeedHealth;
use App\Models\NewsItem;
use App\Models\User;
use App\Services\News\NewsIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeedSourcesUiTest extends TestCase
{
    use RefreshDatabase;

    private function configureFeeds(): void
    {
        config(['news.feeds' => [
            ['key' => 'alpha', 'name' => 'Alpha News', 'url' => 'https://alpha.test/rss', 'market' => 'US', 'language' => 'en'],
            ['key' => 'cnyes_headline', 'name' => '鉅亨網', 'driver' => 'cnyes', 'category' => 'headline', 'site' => 'https://news.cnyes.com/news/cat/headline', 'market' => 'TW', 'language' => 'zh-TW'],
        ]]);
    }

    public function test_news_page_lists_every_configured_source(): void
    {
        $this->configureFeeds();

        $this->actingAs(User::factory()->create())
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('feedSources', 2)
                ->where('feedSources.0.name', 'Alpha News')
                ->where('feedSources.0.market', 'US'));
    }

    /** 沒有 site 的 feed 由 url 主機名推出可點連結，UI 才有東西可以連。 */
    public function test_site_link_falls_back_to_the_feed_host(): void
    {
        $this->configureFeeds();

        $this->actingAs(User::factory()->create())
            ->get('/news')
            ->assertInertia(fn (Assert $page) => $page->where('feedSources.0.site', 'https://alpha.test'));
    }

    /** 明確設定的 site 優先（cnyes 走 JSON API，沒有可供人閱讀的 feed URL）。 */
    public function test_explicit_site_takes_precedence(): void
    {
        $this->configureFeeds();

        $this->actingAs(User::factory()->create())
            ->get('/news')
            ->assertInertia(fn (Assert $page) => $page
                ->where('feedSources.1.site', 'https://news.cnyes.com/news/cat/headline'));
    }

    /**
     * 失效來源必須在 UI 可見。這是本功能的重點：後端的失效是靜默的，
     * 使用者只會看到某個媒體的新聞消失卻不知原因。
     */
    public function test_unhealthy_source_is_flagged_with_its_reason(): void
    {
        $this->configureFeeds();
        config(['news.health.stale_runs_threshold' => 3]);

        FeedHealth::query()->create([
            'key' => 'alpha',
            'name' => 'Alpha News',
            'last_item_count' => 20,
            'last_fresh_count' => 0,
            'consecutive_stale_runs' => 5,
            'last_run_at' => now(),
            'last_fresh_at' => now()->subDays(547),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/news')
            ->assertInertia(fn (Assert $page) => $page
                ->where('feedSources.0.healthy', false)
                ->where('feedSources.0.stale_runs', 5)
                ->whereNot('feedSources.0.last_fresh_at', null));
    }

    /** 從未執行過的 feed 健康度為 null，不能誤標成失效。 */
    public function test_source_without_health_history_is_not_marked_broken(): void
    {
        $this->configureFeeds();

        $this->actingAs(User::factory()->create())
            ->get('/news')
            ->assertInertia(fn (Assert $page) => $page->where('feedSources.0.healthy', null));
    }

    // --- 來源封鎖 ---

    /**
     * 個股新聞（SymbolNewsService）直接呼叫 upsert()，不經 ingest() 迴圈。
     * 封鎖檢查若只放在迴圈裡，內容農場照樣能從個股頁進到共用新聞流——而個股
     * Google News 正是內容農場最主要的來源。
     */
    public function test_blocked_source_cannot_enter_through_direct_upsert(): void
    {
        config(['news.blocked_sources' => ['Insider Monkey']]);

        $service = app(NewsIngestionService::class);

        $service->upsert($this->item('Insider Monkey', 'Ten stocks to buy now', 'https://farm.test/1'), ['NVDA']);
        $service->upsert($this->item('Reuters', 'Fed holds rates steady', 'https://reuters.test/1'), ['NVDA']);

        $this->assertSame(0, NewsItem::where('source', 'Insider Monkey')->count());
        $this->assertSame(1, NewsItem::where('source', 'Reuters')->count());
    }

    private function item(string $source, string $title, string $url): NewsItemData
    {
        return new NewsItemData(
            source: $source,
            title: $title,
            summary: '',
            topic: 'macro',
            relatedSymbols: [],
            publishedAt: now()->subHour()->toIso8601String(),
            url: $url,
            language: 'en',
            market: 'US',
        );
    }

    /** 內容農場不得入庫；Google News 會把它們一併帶進共用新聞流。 */
    public function test_blocked_sources_are_filtered_out(): void
    {
        config(['news.blocked_sources' => ['Insider Monkey', '豐雲學堂']]);

        $service = app(NewsIngestionService::class);

        $this->assertTrue($service->isBlocked('Insider Monkey'));
        $this->assertTrue($service->isBlocked('insider monkey'));
        $this->assertTrue($service->isBlocked('豐雲學堂 - 投資教學'));
        $this->assertFalse($service->isBlocked('CNBC'));
        $this->assertFalse($service->isBlocked(''));
    }
}
