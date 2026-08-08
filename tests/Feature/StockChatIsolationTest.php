<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Models\Instrument;
use App\Models\StockChatTurn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 問答屬個別使用者：內容含使用者自己輸入的提問，跨使用者外洩比一般資料更敏感。
 */
class StockChatIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTurn(User $user, Instrument $instrument, string $question): StockChatTurn
    {
        return $user->stockChatTurns()->create([
            'instrument_id' => $instrument->id,
            'provider_type' => 'ollama',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Completed,
            'question' => $question,
            'answer' => '回答',
            'metadata' => [],
            'data_as_of' => now(),
        ]);
    }

    public function test_user_cannot_delete_another_users_turn(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);
        $turn = $this->makeTurn($owner, $instrument, '我的問題');

        $this->actingAs($other)->delete("/stocks/chat/{$turn->id}")->assertForbidden();

        $this->assertDatabaseHas('stock_chat_turns', ['id' => $turn->id]);
    }

    public function test_clearing_does_not_touch_another_users_turns(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);
        $ownerTurn = $this->makeTurn($owner, $instrument, '擁有者的問題');
        $otherTurn = $this->makeTurn($other, $instrument, '另一人的問題');

        $this->actingAs($other)->delete("/stocks/{$instrument->id}/chat")->assertRedirect();

        $this->assertDatabaseHas('stock_chat_turns', ['id' => $ownerTurn->id]);
        $this->assertDatabaseMissing('stock_chat_turns', ['id' => $otherTurn->id]);
    }

    public function test_other_users_turns_are_not_exposed_on_the_stock_page(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL']);
        $this->makeTurn($owner, $instrument, '擁有者的問題');

        $props = $this->actingAs($other)
            ->get('/stocks/search?symbol=AAPL')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([], $props['chatTurns']);
    }

    public function test_posting_with_another_users_provider_setting_is_forbidden(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $setting = $owner->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Owner Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);

        $this->actingAs($other)
            ->post("/stocks/{$instrument->id}/chat", [
                'question' => '技術面如何？',
                'llm_provider_setting_id' => $setting->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('stock_chat_turns', 0);
        Queue::assertNothingPushed();
    }

    public function test_guests_cannot_post_a_question(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $this->post("/stocks/{$instrument->id}/chat", ['question' => '技術面如何？'])
            ->assertRedirect('/login');
    }
}
