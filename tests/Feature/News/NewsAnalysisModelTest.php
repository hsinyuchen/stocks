<?php

namespace Tests\Feature\News;

use App\Models\NewsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsAnalysisModelTest extends TestCase
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

    public function test_user_can_create_item_analysis_linked_to_news_item(): void
    {
        $user = User::factory()->create();
        $item = $this->makeItem();

        $analysis = $user->newsAnalyses()->create([
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'ollama',
            'model' => 'llama3',
            'prompt_version' => 'v1',
            'sentiment' => 'bullish',
            'impact_score' => 4,
            'related_symbols' => ['NVDA', 'AMD'],
            'summary' => 'one line',
            'reasoning' => 'short reasoning',
            'raw_output' => ['content' => '{"sentiment":"bullish"}'],
            'data_as_of' => now(),
        ]);

        $this->assertDatabaseHas('news_analyses', [
            'id' => $analysis->id,
            'user_id' => $user->id,
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'ollama',
            'sentiment' => 'bullish',
            'impact_score' => 4,
        ]);

        $fresh = $analysis->fresh();
        $this->assertSame(['NVDA', 'AMD'], $fresh->related_symbols);
        $this->assertSame(['content' => '{"sentiment":"bullish"}'], $fresh->raw_output);
        $this->assertSame(4, $fresh->impact_score);
        $this->assertNotNull($fresh->data_as_of);
        $this->assertTrue($user->is($fresh->user));
        $this->assertTrue($item->is($fresh->newsItem));
    }

    public function test_user_can_create_daily_summary_with_null_news_item(): void
    {
        $user = User::factory()->create();

        $analysis = $user->newsAnalyses()->create([
            'news_item_id' => null,
            'type' => 'daily_summary',
            'provider_type' => 'ollama',
            'model' => 'llama3',
            'prompt_version' => 'v1',
            'related_symbols' => ['NVDA'],
            'summary' => 'today macro summary',
            'raw_output' => ['points' => ['a', 'b']],
            'data_as_of' => now(),
        ]);

        $this->assertDatabaseHas('news_analyses', [
            'id' => $analysis->id,
            'user_id' => $user->id,
            'news_item_id' => null,
            'type' => 'daily_summary',
        ]);

        $fresh = $analysis->fresh();
        $this->assertNull($fresh->news_item_id);
        $this->assertNull($fresh->newsItem);
        $this->assertSame(['points' => ['a', 'b']], $fresh->raw_output);
    }

    public function test_user_news_analyses_relation_returns_only_owned_rows(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $item = $this->makeItem();

        $user->newsAnalyses()->create([
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'ollama',
            'model' => 'llama3',
            'prompt_version' => 'v1',
            'sentiment' => 'neutral',
            'data_as_of' => now(),
        ]);

        $other->newsAnalyses()->create([
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'ollama',
            'model' => 'llama3',
            'prompt_version' => 'v1',
            'sentiment' => 'bearish',
            'data_as_of' => now(),
        ]);

        $this->assertCount(1, $user->newsAnalyses()->get());
        $this->assertSame('neutral', $user->newsAnalyses()->first()->sentiment);
    }
}
