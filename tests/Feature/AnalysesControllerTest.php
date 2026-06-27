<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\NewsItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalysesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_analyses_to_login(): void
    {
        $this->get('/analyses')
            ->assertRedirect('/login');
    }

    public function test_index_lists_all_user_analyses_newest_first(): void
    {
        $user = User::factory()->create();

        $this->seedUserAnalyses($user);

        $this->actingAs($user)
            ->get('/analyses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analyses/Index')
                ->where('auth.user.id', $user->id)
                ->where('filters.type', 'all')
                ->has('items', 3)
                // Newest first: daily_summary (newest), then item, then stock.
                ->where('items.0.kind', 'daily')
                ->where('items.1.kind', 'news')
                ->where('items.2.kind', 'stock')
                ->where('items.2.label', '2330.TW')
                ->where('items.2.stance', 'bullish'));
    }

    public function test_type_filter_stock_returns_only_stock_analyses(): void
    {
        $user = User::factory()->create();

        $this->seedUserAnalyses($user);

        $this->actingAs($user)
            ->get('/analyses?type=stock')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analyses/Index')
                ->where('filters.type', 'stock')
                ->has('items', 1)
                ->where('items.0.kind', 'stock')
                ->where('items.0.label', '2330.TW'));
    }

    public function test_type_filter_news_returns_only_item_news_analyses(): void
    {
        $user = User::factory()->create();

        $this->seedUserAnalyses($user);

        $this->actingAs($user)
            ->get('/analyses?type=news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analyses/Index')
                ->where('filters.type', 'news')
                ->has('items', 1)
                ->where('items.0.kind', 'news')
                ->where('items.0.label', '台積電法說會釋出展望'));
    }

    public function test_type_filter_daily_returns_only_daily_summary_analyses(): void
    {
        $user = User::factory()->create();

        $this->seedUserAnalyses($user);

        $this->actingAs($user)
            ->get('/analyses?type=daily')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analyses/Index')
                ->where('filters.type', 'daily')
                ->has('items', 1)
                ->where('items.0.kind', 'daily'));
    }

    public function test_analyses_are_isolated_per_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->seedUserAnalyses($owner);

        $this->actingAs($other)
            ->get('/analyses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analyses/Index')
                ->has('items', 0));
    }

    public function test_invalid_type_filter_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/analyses')
            ->get('/analyses?type=bogus')
            ->assertRedirect('/analyses')
            ->assertInvalid(['type']);
    }

    private function seedUserAnalyses(User $user): void
    {
        $instrument = Instrument::factory()->create([
            'symbol' => '2330.TW',
            'name' => '台積電',
            'market' => 'TW',
            'currency' => 'TWD',
        ]);

        // Timestamps are not in the models' $fillable, so let the auto-managed
        // created_at drive ordering by travelling through time per row.

        // Oldest: a stock analysis.
        $this->travelTo(CarbonImmutable::parse('2026-06-20T08:00:00+08:00'));
        $user->stockAnalyses()->create([
            'instrument_id' => $instrument->id,
            'technical_snapshot_id' => null,
            'provider_type' => 'fake',
            'model' => 'fake-model',
            'prompt_version' => 'v1',
            'rule_signal' => ['stance' => 'bullish'],
            'llm_output' => ['content' => '參考分析內容'],
            'data_as_of' => CarbonImmutable::parse('2026-06-20T08:00:00+08:00'),
        ]);

        $newsItem = NewsItem::create([
            'source' => '財經日報',
            'title' => '台積電法說會釋出展望',
            'summary' => '重點摘要',
            'url' => 'https://example.com/tsmc',
            'published_at' => CarbonImmutable::parse('2026-06-21T08:00:00+08:00'),
            'language' => 'zh-TW',
            'market' => 'TW',
            'topic' => 'macro',
            'domain' => 'tech',
            'kind' => 'article',
            'related_symbols' => ['2330.TW'],
        ]);

        // Middle: an item news analysis.
        $this->travelTo(CarbonImmutable::parse('2026-06-21T08:00:00+08:00'));
        $user->newsAnalyses()->create([
            'news_item_id' => $newsItem->id,
            'type' => 'item',
            'provider_type' => 'fake',
            'model' => 'fake-model',
            'prompt_version' => 'v1',
            'sentiment' => 'bullish',
            'impact_score' => 4,
            'related_symbols' => ['2330.TW'],
            'summary' => '此新聞偏多',
            'reasoning' => '法說會展望樂觀',
            'raw_output' => [],
            'data_as_of' => CarbonImmutable::parse('2026-06-21T08:00:00+08:00'),
        ]);

        // Newest: a daily summary news analysis.
        $this->travelTo(CarbonImmutable::parse('2026-06-22T08:00:00+08:00'));
        $user->newsAnalyses()->create([
            'news_item_id' => null,
            'type' => 'daily_summary',
            'provider_type' => 'fake',
            'model' => 'fake-model',
            'prompt_version' => 'v1',
            'sentiment' => 'neutral',
            'impact_score' => null,
            'related_symbols' => ['2330.TW', 'NVDA'],
            'summary' => '今日總經中性',
            'reasoning' => null,
            'raw_output' => ['points' => ['重點一', '重點二']],
            'data_as_of' => CarbonImmutable::parse('2026-06-22T08:00:00+08:00'),
        ]);

        $this->travelBack();
    }
}
