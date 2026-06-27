<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\NewsItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('auth.user.id', $user->id)
                ->has('marketSnapshot')
                ->has('watchlistMovers')
                ->has('latestNews')
                ->has('recentAnalyses')
                ->where('disclaimer', '本頁資訊與 AI 分析僅供研究參考，不構成投資建議。')
                // Market snapshot is deterministic via the fake provider (3 configured indices).
                ->has('marketSnapshot', 3)
                ->where('marketSnapshot.0.symbol', '^TWII')
                ->where('marketSnapshot.0.price', 128.5)
                // Each snapshot index carries a sparkline of recent closes.
                ->has('marketSnapshot.0.spark')
                // Watchlist mover for the single instrument with a rule-based stance.
                ->has('watchlistMovers', 1)
                ->where('watchlistMovers.0.symbol', '2330.TW')
                ->where('watchlistMovers.0.name', '台積電')
                ->has('watchlistMovers.0.stance')
                ->has('watchlistMovers.0.spark')
                // Latest news prefers the watchlist-related item.
                ->has('latestNews', 1)
                ->where('latestNews.0.title', '台積電法說會釋出展望')
                ->where('latestNews.0.source', '財經日報')
                // Recent analyses include the user's stock analysis.
                ->has('recentAnalyses', 1)
                ->where('recentAnalyses.0.type', 'stock')
                ->where('recentAnalyses.0.label', '2330.TW')
                ->where('recentAnalyses.0.stance', 'bullish'));
    }

    public function test_dashboard_renders_for_user_without_any_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('marketSnapshot', 3)
                ->has('watchlistMovers', 0)
                ->has('latestNews', 0)
                ->has('recentAnalyses', 0)
                ->where('disclaimer', '本頁資訊與 AI 分析僅供研究參考，不構成投資建議。'));
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

        $this->actingAs($other)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('watchlistMovers', 0)
                ->has('recentAnalyses', 0));
    }

    public function test_dashboard_is_cached_per_session_and_refresh_busts_it(): void
    {
        $user = User::factory()->create();
        $this->makeNewsItem('第一則新聞');

        // First load builds + caches (one news item) and exposes the build time.
        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->has('latestNews', 1)->has('generatedAt'));

        // A second item arrives after the cache was built.
        $this->makeNewsItem('第二則新聞');

        // Re-entering serves the cached payload — still only the first item.
        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->has('latestNews', 1));

        // The refresh button busts the cache and pulls the latest.
        $this->actingAs($user)->get('/dashboard?refresh=1')
            ->assertInertia(fn (Assert $page) => $page->has('latestNews', 2));
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
