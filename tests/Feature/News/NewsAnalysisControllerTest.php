<?php

namespace Tests\Feature\News;

use App\Models\LlmProviderSetting;
use App\Models\NewsAnalysis;
use App\Models\NewsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NewsAnalysisControllerTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $content
     */
    private function fakeChatCompletion(array $content): void
    {
        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response([
                'model' => 'llama3.1',
                'choices' => [
                    ['message' => ['content' => json_encode($content, JSON_UNESCAPED_UNICODE)]],
                ],
                'usage' => ['total_tokens' => 42],
            ], 200),
        ]);
    }

    public function test_guest_cannot_store_item_analysis(): void
    {
        $item = $this->makeItem();

        $this->post("/news/{$item->id}/analyses")->assertRedirect('/login');
    }

    public function test_guest_cannot_store_daily_summary(): void
    {
        $this->post('/news/daily-summary')->assertRedirect('/login');
    }

    public function test_user_with_setting_stores_item_analysis(): void
    {
        $user = $this->createUserWithProfile();
        $setting = $this->makeOllamaSetting($user);
        $item = $this->makeItem();

        $this->fakeChatCompletion([
            'sentiment' => 'bullish',
            'impact' => 4,
            'symbols' => ['NVDA'],
            'summary' => 's',
            'reasoning' => 'r',
        ]);

        $this->actingAs($user)
            ->post("/news/{$item->id}/analyses")
            ->assertRedirect();

        $this->assertDatabaseHas('news_analyses', [
            'user_id' => $user->id,
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'ollama',
            'sentiment' => 'bullish',
            'impact_score' => 4,
        ]);

        $analysis = NewsAnalysis::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['NVDA'], $analysis->related_symbols);
        $this->assertSame('s', $analysis->summary);
        $this->assertSame('r', $analysis->reasoning);
        $this->assertNotNull($analysis->data_as_of);
    }

    public function test_user_can_choose_a_specific_owned_setting(): void
    {
        $user = $this->createUserWithProfile();
        $this->makeOllamaSetting($user, ['display_name' => 'Default', 'is_default' => true, 'default_marker' => true]);
        $chosen = $this->makeOllamaSetting($user, [
            'display_name' => 'Chosen',
            'model' => 'llama3.2',
            'is_default' => false,
            'default_marker' => null,
        ]);
        $item = $this->makeItem();

        $this->fakeChatCompletion([
            'sentiment' => 'bearish',
            'impact' => 2,
            'symbols' => [],
            'summary' => 'chosen summary',
            'reasoning' => 'chosen reasoning',
        ]);

        $this->actingAs($user)
            ->post("/news/{$item->id}/analyses", ['llm_provider_setting_id' => $chosen->id])
            ->assertRedirect();

        // The chosen (non-default) setting's model is what gets sent to the provider.
        Http::assertSent(fn ($request) => $request['model'] === 'llama3.2');

        $this->assertDatabaseHas('news_analyses', [
            'user_id' => $user->id,
            'news_item_id' => $item->id,
            'sentiment' => 'bearish',
        ]);
    }

    public function test_choosing_another_users_setting_is_forbidden(): void
    {
        $user = $this->createUserWithProfile();
        $this->makeOllamaSetting($user);
        $other = $this->createUserWithProfile();
        $otherSetting = $this->makeOllamaSetting($other);
        $item = $this->makeItem();

        Http::fake();

        $this->actingAs($user)
            ->post("/news/{$item->id}/analyses", ['llm_provider_setting_id' => $otherSetting->id])
            ->assertForbidden();

        $this->assertSame(0, NewsAnalysis::count());
        Http::assertNothingSent();
    }

    public function test_user_without_setting_is_blocked_with_flash_and_stores_nothing(): void
    {
        $user = $this->createUserWithProfile();
        $item = $this->makeItem();

        Http::fake();

        $this->actingAs($user)
            ->post("/news/{$item->id}/analyses")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, NewsAnalysis::count());
        Http::assertNothingSent();
    }

    public function test_daily_summary_stores_summary_row_with_null_news_item(): void
    {
        $user = $this->createUserWithProfile();
        $this->makeOllamaSetting($user);
        $this->makeItem(['title' => 'a', 'published_at' => now()]);
        $this->makeItem(['title' => 'b', 'published_at' => now()->subHours(2)]);

        $this->fakeChatCompletion([
            'summary' => 'today macro summary',
            'points' => ['point one', 'point two'],
            'symbols' => ['SPY'],
        ]);

        $this->actingAs($user)
            ->post('/news/daily-summary')
            ->assertRedirect();

        $this->assertDatabaseHas('news_analyses', [
            'user_id' => $user->id,
            'news_item_id' => null,
            'type' => 'daily_summary',
            'provider_type' => 'ollama',
            'summary' => 'today macro summary',
        ]);

        $analysis = NewsAnalysis::where('type', 'daily_summary')->firstOrFail();
        $this->assertNull($analysis->news_item_id);
        $this->assertNull($analysis->impact_score);
        $this->assertSame(['SPY'], $analysis->related_symbols);
    }

    private function createUserWithProfile(): User
    {
        return User::factory()->create();
    }
}
