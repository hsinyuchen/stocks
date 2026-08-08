<?php

namespace Tests\Feature;

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuoteData;
use App\Models\Alert;
use App\Models\Instrument;
use App\Models\NewsItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_dashboard_to_login(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_dashboard_aggregates_real_per_user_data(): void
    {
        $user = User::factory()->create();

        $instrument = Instrument::factory()->create([
            'symbol' => '2330.TW',
            'name' => '台積電',
            'market' => 'TW',
            'currency' => 'TWD',
        ]);

        $watchlist = $user->watchlists()->create(['name' => '核心持股']);
        $watchlist->items()->create([
            'instrument_id' => $instrument->id,
            'sort_order' => 0,
        ]);

        NewsItem::create([
            'source' => '財經日報',
            'title' => '台積電法說會釋出展望',
            'summary' => '重點摘要',
            'url' => 'https://example.com/tsmc',
            'published_at' => CarbonImmutable::parse('2026-06-24T08:00:00+08:00'),
            'language' => 'zh-TW',
            'market' => 'TW',
            'topic' => 'macro',
            'domain' => 'tech',
            'kind' => 'article',
            'related_symbols' => ['2330.TW'],
        ]);

        $user->stockAnalyses()->create([
            'instrument_id' => $instrument->id,
            'technical_snapshot_id' => null,
            'provider_type' => 'fake',
            'model' => 'fake-model',
            'prompt_version' => 'v1',
            'rule_signal' => ['stance' => 'bullish'],
            'llm_output' => ['content' => '參考分析內容'],
            'data_as_of' => CarbonImmutable::parse('2026-06-24T08:00:00+08:00'),
        ]);

        $response = $this->actingAs($user)->getDashboard()->assertOk();

        $response->assertJsonPath('component', 'Dashboard');
        $response->assertJsonPath('props.auth.user.id', $user->id);
        $response->assertJsonPath('props.disclaimer', '本頁資訊與 AI 分析僅供研究參考，不構成投資建議。');
        // Market snapshot is deterministic via the fake provider (3 configured indices).
        $response->assertJsonCount(3, 'props.marketSnapshot');
        $response->assertJsonPath('props.marketSnapshot.0.symbol', '^TWII');
        $response->assertJsonPath('props.marketSnapshot.0.price', 128.5);
        // Each snapshot index carries a sparkline of recent closes.
        $this->assertNotNull($response->json('props.marketSnapshot.0.spark'));
        // Watchlist mover for the single instrument with a rule-based stance.
        $response->assertJsonCount(1, 'props.watchlistMovers');
        $response->assertJsonPath('props.watchlistMovers.0.symbol', '2330.TW');
        $response->assertJsonPath('props.watchlistMovers.0.name', '台積電');
        $this->assertNotNull($response->json('props.watchlistMovers.0.stance'));
        $this->assertNotNull($response->json('props.watchlistMovers.0.spark'));
        // Latest news prefers the watchlist-related item.
        $response->assertJsonCount(1, 'props.latestNews');
        $response->assertJsonPath('props.latestNews.0.title', '台積電法說會釋出展望');
        $response->assertJsonPath('props.latestNews.0.source', '財經日報');
        // Recent analyses include the user's stock analysis.
        $response->assertJsonCount(1, 'props.recentAnalyses');
        $response->assertJsonPath('props.recentAnalyses.0.type', 'stock');
        $response->assertJsonPath('props.recentAnalyses.0.label', '2330.TW');
        $response->assertJsonPath('props.recentAnalyses.0.stance', 'bullish');
        // 未設定 AI 模型時，前端據此顯示設定引導提示。
        $response->assertJsonPath('props.hasLlmProvider', false);
    }

    public function test_dashboard_reports_llm_provider_presence(): void
    {
        $user = User::factory()->create();
        $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Local Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('hasLlmProvider', true));
    }

    public function test_dashboard_renders_for_user_without_any_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getDashboard()->assertOk();

        $response->assertJsonPath('component', 'Dashboard');
        $response->assertJsonCount(3, 'props.marketSnapshot');
        $response->assertJsonCount(0, 'props.watchlistMovers');
        $response->assertJsonCount(0, 'props.latestNews');
        $response->assertJsonCount(0, 'props.recentAnalyses');
        $response->assertJsonPath('props.disclaimer', '本頁資訊與 AI 分析僅供研究參考，不構成投資建議。');
    }

    public function test_dashboard_is_isolated_per_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'name' => 'NVIDIA']);
        $watchlist = $owner->watchlists()->create(['name' => '美股']);
        $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);

        $owner->stockAnalyses()->create([
            'instrument_id' => $instrument->id,
            'technical_snapshot_id' => null,
            'provider_type' => 'fake',
            'model' => 'fake-model',
            'prompt_version' => 'v1',
            'rule_signal' => ['stance' => 'neutral'],
            'llm_output' => ['content' => 'x'],
            'data_as_of' => CarbonImmutable::now(),
        ]);

        $response = $this->actingAs($other)->getDashboard()->assertOk();

        $response->assertJsonPath('component', 'Dashboard');
        $response->assertJsonCount(0, 'props.watchlistMovers');
        $response->assertJsonCount(0, 'props.recentAnalyses');
    }

    public function test_dashboard_is_cached_per_session_and_refresh_busts_it(): void
    {
        $user = User::factory()->create();
        $this->makeNewsItem('第一則新聞');

        // First load builds + caches (one news item) and exposes the build time.
        $first = $this->actingAs($user)->getDashboard()->assertOk();
        $first->assertJsonCount(1, 'props.latestNews');
        $this->assertNotNull($first->json('props.generatedAt'));

        // A second item arrives after the cache was built.
        $this->makeNewsItem('第二則新聞');

        // Re-entering serves the cached payload — still only the first item.
        $this->actingAs($user)->getDashboard()->assertOk()->assertJsonCount(1, 'props.latestNews');

        // The refresh button busts the cache and pulls the latest.
        $this->actingAs($user)->getDashboard(true)->assertOk()->assertJsonCount(2, 'props.latestNews');
    }

    public function test_refresh_ingests_fresh_news_from_live_feeds(): void
    {
        // Switch the stream to "live" so the dashboard refresh runs an ingest.
        config([
            'services.news.driver' => 'db',
            'news.feeds' => [
                ['key' => 'us', 'name' => 'US Feed', 'url' => 'https://feed-us.test/rss', 'market' => 'US', 'language' => 'en'],
            ],
        ]);

        // 發布時間相對於「現在」：ingest() 會依 news.retention_days 立刻 prune，
        // 寫死日期會在超出保留窗口後讓測試失敗。
        $pubDate = now()->subHours(2)->toRfc2822String();

        Http::fake([
            'feed-us.test/*' => Http::response(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>US Feed</title>
              <item>
                <title>Nvidia hits record high on AI demand</title>
                <link>https://feed-us.test/articles/1</link>
                <description>Chip demand surges.</description>
                <pubDate>{$pubDate}</pubDate>
              </item>
            </channel></rss>
            XML, 200),
        ]);

        $user = User::factory()->create();

        $this->assertSame(0, NewsItem::count());

        $response = $this->actingAs($user)->getDashboard(true)->assertOk();
        $response->assertJsonCount(1, 'props.latestNews');
        $response->assertJsonPath('props.latestNews.0.title', 'Nvidia hits record high on AI demand');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'feed-us.test'));
        $this->assertSame(1, NewsItem::count());
    }

    public function test_first_load_skips_ingest_when_news_is_fresh(): void
    {
        config([
            'services.news.driver' => 'db',
            'news.feeds' => [
                ['key' => 'us', 'name' => 'US Feed', 'url' => 'https://feed-us.test/rss', 'market' => 'US', 'language' => 'en'],
            ],
        ]);

        Http::fake();

        $user = User::factory()->create();
        // A recently-published item means the stored news is still fresh.
        $this->makeNewsItem('剛抓到的新聞');

        $this->actingAs($user)->getDashboard()->assertOk()->assertJsonCount(1, 'props.latestNews');

        // No live feed was hit because the stored news was within the window.
        Http::assertNothingSent();
    }

    public function test_dashboard_evaluates_alerts_and_exposes_triggered_outside_cache(): void
    {
        // 綁命中 provider（price 150 > threshold 100）
        $this->app->bind(MarketDataProvider::class, fn () => new class implements MarketDataProvider
        {
            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 150.0, 0.0, 0.0, '2026-07-08T00:00:00+00:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                return [];
            }
        });

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA', 'name' => 'NVDA']);
        $alert = new Alert(['instrument_id' => $instrument->id, 'type' => 'price_above', 'threshold' => 100]);
        $user->alerts()->save($alert);

        $response = $this->actingAs($user)->getDashboard()->assertOk();
        $response->assertJsonCount(1, 'props.triggeredAlerts');
        $response->assertJsonPath('props.triggeredAlerts.0.symbol', 'NVDA');

        $this->assertSame('triggered', $alert->refresh()->status);
    }

    private function makeNewsItem(string $title): void
    {
        NewsItem::create([
            'source' => '財經日報',
            'title' => $title,
            'summary' => 's',
            'url' => 'https://example.com/'.urlencode($title),
            'published_at' => CarbonImmutable::now(),
            'language' => 'zh-TW',
            'market' => 'TW',
            'topic' => 'macro',
            'domain' => 'tech',
            'kind' => 'article',
            'related_symbols' => [],
        ]);
    }
}
