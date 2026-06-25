<?php

namespace Tests\Feature\News;

use App\Models\LlmProviderSetting;
use App\Models\NewsAnalysis;
use App\Models\NewsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NewsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(array $overrides = []): NewsItem
    {
        return NewsItem::create(array_merge([
            'source' => 'CNBC',
            'title' => 'headline',
            'summary' => 'summary',
            'url' => 'https://news.test/'.uniqid('', true),
            'url_hash' => sha1(uniqid('', true)),
            'published_at' => now(),
            'language' => 'en',
            'market' => 'US',
            'topic' => 'macro',
            'domain' => 'tech',
            'related_symbols' => ['NVDA'],
        ], $overrides));
    }

    private function makeOllamaSetting(User $user, array $overrides = []): LlmProviderSetting
    {
        return $user->llmProviderSettings()->create(array_merge([
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
        ], $overrides));
    }

    public function test_guest_is_redirected_from_news_to_login(): void
    {
        $this->get('/news')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_render_news_index(): void
    {
        $user = User::factory()->create();
        $this->makeItem();

        $this->actingAs($user)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('News/Index')
                ->has('items.data', 1)
                ->has('filters')
                ->has('facets.markets')
                ->has('facets.domains')
                ->has('nextUpdateTimes')
                ->where('auth.user.id', $user->id));
    }

    public function test_news_index_lists_items_newest_first(): void
    {
        $user = User::factory()->create();
        $this->makeItem(['title' => 'older', 'published_at' => now()->subDay()]);
        $this->makeItem(['title' => 'newer', 'published_at' => now()]);

        $this->actingAs($user)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.title', 'newer')
                ->where('items.data.1.title', 'older'));
    }

    public function test_news_index_filters_by_market(): void
    {
        $user = User::factory()->create();
        $this->makeItem(['title' => 'us one', 'market' => 'US']);
        $this->makeItem(['title' => 'tw one', 'market' => 'TW']);

        $this->actingAs($user)
            ->get('/news?market=TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.title', 'tw one')
                ->where('filters.market', 'TW'));
    }

    public function test_news_index_filters_by_domain(): void
    {
        $user = User::factory()->create();
        $this->makeItem(['title' => 'tech one', 'domain' => 'tech']);
        $this->makeItem(['title' => 'finance one', 'domain' => 'finance']);

        $this->actingAs($user)
            ->get('/news?domain=finance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.title', 'finance one'));
    }

    public function test_news_index_filters_by_source(): void
    {
        $user = User::factory()->create();
        $this->makeItem(['title' => 'cnbc one', 'source' => 'CNBC']);
        $this->makeItem(['title' => 'udn one', 'source' => '經濟日報']);

        $this->actingAs($user)
            ->get('/news?source='.urlencode('經濟日報'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.title', 'udn one'));
    }

    public function test_news_index_filters_by_symbol(): void
    {
        $user = User::factory()->create();
        $this->makeItem(['title' => 'nvda one', 'related_symbols' => ['NVDA']]);
        $this->makeItem(['title' => 'aapl one', 'related_symbols' => ['AAPL']]);

        $this->actingAs($user)
            ->get('/news?symbol=AAPL')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.title', 'aapl one'));
    }

    public function test_news_index_filters_by_keyword(): void
    {
        $user = User::factory()->create();
        $this->makeItem(['title' => 'semiconductor surge', 'summary' => 'chips rally']);
        $this->makeItem(['title' => 'bond market', 'summary' => 'yields fall']);

        $this->actingAs($user)
            ->get('/news?q=semiconductor')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.title', 'semiconductor surge'));
    }

    public function test_news_index_filters_by_kind(): void
    {
        $user = User::factory()->create();
        $this->makeItem(['title' => 'an article', 'kind' => 'article']);
        $this->makeItem(['title' => 'a video', 'kind' => 'video']);

        $this->actingAs($user)
            ->get('/news?kind=video')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.title', 'a video')
                ->where('items.data.0.kind', 'video')
                ->where('filters.kind', 'video'));
    }

    public function test_news_index_exposes_kind_in_item_payload(): void
    {
        $user = User::factory()->create();
        $this->makeItem(['kind' => 'video']);

        $this->actingAs($user)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.kind', 'video'));
    }

    public function test_news_index_rejects_invalid_kind(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/news?kind=podcast')
            ->assertSessionHasErrors('kind');
    }

    public function test_news_index_filters_by_date_range(): void
    {
        $user = User::factory()->create();
        $this->makeItem(['title' => 'too old', 'published_at' => now()->subDays(10)]);
        $this->makeItem(['title' => 'in range', 'published_at' => now()->subDays(2)]);
        $this->makeItem(['title' => 'too new', 'published_at' => now()->addDays(2)]);

        $from = now()->subDays(5)->toDateString();
        $to = now()->toDateString();

        $this->actingAs($user)
            ->get("/news?from={$from}&to={$to}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.title', 'in range'));
    }

    public function test_news_index_paginates(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 35; $i++) {
            $this->makeItem(['title' => "item {$i}", 'published_at' => now()->subMinutes($i)]);
        }

        $this->actingAs($user)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 30)
                ->where('items.total', 35));
    }

    public function test_news_index_rejects_invalid_market(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/news?market='.str_repeat('x', 40))
            ->assertSessionHasErrors('market');
    }

    public function test_news_index_exposes_user_llm_providers(): void
    {
        $user = User::factory()->create();
        $this->makeOllamaSetting($user, ['display_name' => 'My Model']);
        $this->makeItem();

        $this->actingAs($user)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('llmProviders', 1)
                ->where('llmProviders.0.display_name', 'My Model')
                ->where('llmProviders.0.provider_type', 'ollama')
                ->where('llmProviders.0.model', 'llama3.1')
                ->where('llmProviders.0.is_default', true)
                ->has('llmProviders.0.id'));
    }

    public function test_news_index_has_empty_llm_providers_when_none_configured(): void
    {
        $user = User::factory()->create();
        $this->makeItem();

        $this->actingAs($user)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('llmProviders', 0)
                ->where('latestDailySummary', null));
    }

    public function test_news_index_attaches_latest_item_analysis(): void
    {
        $user = User::factory()->create();
        $item = $this->makeItem();

        $user->newsAnalyses()->create([
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'ollama',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'sentiment' => 'bullish',
            'impact_score' => 4,
            'related_symbols' => ['NVDA'],
            'summary' => 'looks strong',
            'reasoning' => 'good earnings',
            'raw_output' => ['ok' => true],
            'data_as_of' => now(),
        ]);

        $this->actingAs($user)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.latest_analysis.sentiment', 'bullish')
                ->where('items.data.0.latest_analysis.impact_score', 4)
                ->where('items.data.0.latest_analysis.summary', 'looks strong')
                ->where('items.data.0.latest_analysis.reasoning', 'good earnings'));
    }

    public function test_news_index_uses_most_recent_item_analysis(): void
    {
        $user = User::factory()->create();
        $item = $this->makeItem();

        $user->newsAnalyses()->create([
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'ollama',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'sentiment' => 'bearish',
            'impact_score' => 2,
            'related_symbols' => [],
            'summary' => 'old read',
            'reasoning' => 'stale',
            'raw_output' => [],
            'data_as_of' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $latest = $user->newsAnalyses()->create([
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'ollama',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'sentiment' => 'bullish',
            'impact_score' => 5,
            'related_symbols' => [],
            'summary' => 'fresh read',
            'reasoning' => 'new',
            'raw_output' => [],
            'data_as_of' => now(),
        ]);

        $this->actingAs($user)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.latest_analysis.id', $latest->id)
                ->where('items.data.0.latest_analysis.summary', 'fresh read'));
    }

    public function test_news_index_exposes_latest_daily_summary(): void
    {
        $user = User::factory()->create();
        $this->makeItem();

        $user->newsAnalyses()->create([
            'news_item_id' => null,
            'type' => 'daily_summary',
            'provider_type' => 'ollama',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'sentiment' => null,
            'impact_score' => null,
            'related_symbols' => ['NVDA', 'AAPL'],
            'summary' => 'macro digest',
            'reasoning' => null,
            'raw_output' => ['points' => ['point one']],
            'data_as_of' => now(),
        ]);

        $this->actingAs($user)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('latestDailySummary.summary', 'macro digest')
                ->where('latestDailySummary.type', 'daily_summary'));
    }

    public function test_news_index_does_not_leak_other_users_analyses(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $item = $this->makeItem();

        $owner->newsAnalyses()->create([
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'ollama',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'sentiment' => 'bullish',
            'impact_score' => 4,
            'related_symbols' => [],
            'summary' => 'owner only',
            'reasoning' => 'private',
            'raw_output' => [],
            'data_as_of' => now(),
        ]);

        $owner->newsAnalyses()->create([
            'news_item_id' => null,
            'type' => 'daily_summary',
            'provider_type' => 'ollama',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'related_symbols' => [],
            'summary' => 'owner summary',
            'raw_output' => [],
            'data_as_of' => now(),
        ]);

        $this->actingAs($other)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.latest_analysis', null)
                ->where('latestDailySummary', null));
    }
}
