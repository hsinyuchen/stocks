<?php

namespace Tests\Feature\News;

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
}
