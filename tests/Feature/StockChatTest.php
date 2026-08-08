<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Jobs\RunStockChatReply;
use App\Models\Instrument;
use App\Models\LlmProviderSetting;
use App\Models\StockChatTurn;
use App\Models\User;
use App\Services\Analysis\StockChatService;
use App\Services\Llm\LlmProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 個股 AI 問答的端到端行為。
 *
 * 與既有分析一樣，重點在「request 不等待 LLM」：controller 只落地一筆 pending
 * 就回應，答案由 job 補完。測試環境 queue driver 是 sync，所以不 fake queue 時
 * job 會就地跑完，等同 worker 取件。
 */
class StockChatTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'http://localhost:11434/v1/chat/completions';

    private function makeSetting(User $user): LlmProviderSetting
    {
        return $user->llmProviderSettings()->create([
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
    }

    private function fakeAnswer(string $answer): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'model' => 'llama3.1',
                'choices' => [['message' => [
                    'content' => json_encode(['decision' => 'answer', 'answer' => $answer], JSON_UNESCAPED_UNICODE),
                ]]],
            ], 200),
        ]);
    }

    public function test_chat_request_creates_a_pending_turn_without_calling_the_llm(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $this->actingAs($user)
            ->post("/stocks/{$instrument->id}/chat", ['question' => '技術面最近怎麼樣？'])
            ->assertRedirect();

        $turn = StockChatTurn::query()->sole();
        $this->assertSame(AnalysisStatus::Pending, $turn->status);
        $this->assertSame('pending', $turn->provider_type);
        $this->assertSame('技術面最近怎麼樣？', $turn->question);
        $this->assertNull($turn->answer);

        Queue::assertPushed(RunStockChatReply::class, 1);
    }

    public function test_queued_job_fills_in_the_answer(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);
        $this->fakeAnswer('技術面偏多，但要留意量能。');

        $this->actingAs($user)->post("/stocks/{$instrument->id}/chat", ['question' => '技術面如何？']);

        $turn = StockChatTurn::query()->sole();
        $this->assertSame(AnalysisStatus::Completed, $turn->status);
        $this->assertSame('ollama', $turn->provider_type);
        $this->assertSame('技術面偏多，但要留意量能。', $turn->answer);
        $this->assertTrue($turn->metadata['structured']);
        $this->assertFalse($turn->metadata['refused']);
    }

    public function test_turn_is_marked_failed_when_the_provider_errors(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        Http::fake([self::ENDPOINT => Http::response([], 500)]);

        $this->actingAs($user)->post("/stocks/{$instrument->id}/chat", ['question' => '技術面如何？']);

        $turn = StockChatTurn::query()->sole();
        $this->assertSame(AnalysisStatus::Failed, $turn->status);
        $this->assertSame('error', $turn->provider_type);
        $this->assertSame('server_error', $turn->metadata['failure']['reason']);
        $this->assertNotEmpty($turn->answer);
    }

    /** 沒有模型設定時完全不建立紀錄——聊天沒有可降級的本地內容。 */
    public function test_chat_is_refused_when_the_user_has_no_llm_setting(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $this->actingAs($user)
            ->post("/stocks/{$instrument->id}/chat", ['question' => '技術面如何？'])
            ->assertSessionHasErrors('question');

        $this->assertDatabaseCount('stock_chat_turns', 0);
        Queue::assertNothingPushed();
    }

    public function test_question_is_required_and_capped(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $this->actingAs($user)
            ->post("/stocks/{$instrument->id}/chat", ['question' => ''])
            ->assertSessionHasErrors('question');

        $this->actingAs($user)
            ->post("/stocks/{$instrument->id}/chat", ['question' => str_repeat('啊', 501)])
            ->assertSessionHasErrors('question');

        $this->assertDatabaseCount('stock_chat_turns', 0);
    }

    /**
     * pending 中不得再送出下一題。
     *
     * 這是正確性而非單純限流：historyBefore() 只取 completed，Q1 未完成時送出的
     * Q2 會拿到缺一輪的歷史，追問就會指向錯的前文。
     */
    public function test_second_question_is_rejected_while_one_is_pending(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $this->actingAs($user)->post("/stocks/{$instrument->id}/chat", ['question' => '第一題']);
        $this->actingAs($user)
            ->post("/stocks/{$instrument->id}/chat", ['question' => '第二題'])
            ->assertSessionHasErrors('question');

        $this->assertDatabaseCount('stock_chat_turns', 1);
        Queue::assertPushed(RunStockChatReply::class, 1);
    }

    public function test_chat_turns_are_exposed_to_the_stock_page(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL']);

        $this->actingAs($user)->post("/stocks/{$instrument->id}/chat", ['question' => '技術面如何？']);

        $props = $this->actingAs($user)
            ->get('/stocks/search?symbol=AAPL')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('pending', $props['chatTurns'][0]['status']);
        $this->assertSame('技術面如何？', $props['chatTurns'][0]['question']);
    }

    /** 沒有 symbol 的空狀態也要有這個 prop，否則部分重載會讓前端拿到 undefined。 */
    public function test_chat_turns_prop_exists_even_without_a_symbol(): void
    {
        $user = User::factory()->create();

        $props = $this->actingAs($user)->get('/stocks/search')->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey('chatTurns', $props);
        $this->assertSame([], $props['chatTurns']);
    }

    public function test_deleting_a_pending_turn_cancels_the_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $setting = $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $this->actingAs($user)->post("/stocks/{$instrument->id}/chat", ['question' => '技術面如何？']);
        $turn = StockChatTurn::query()->sole();

        $this->actingAs($user)->delete("/stocks/chat/{$turn->id}")->assertRedirect();
        $this->assertDatabaseCount('stock_chat_turns', 0);

        // 紀錄不在了，job 必須靜默結束而不是炸掉或再打一次 LLM。
        Http::preventStrayRequests();
        (new RunStockChatReply($turn->id, $setting->id, 'llama3.1'))->handle(
            app(StockChatService::class),
            app(LlmProviderFactory::class),
        );

        $this->assertDatabaseCount('stock_chat_turns', 0);
    }

    public function test_job_marks_failed_when_the_setting_is_deleted_before_it_runs(): void
    {
        $user = User::factory()->create();
        $setting = $this->makeSetting($user);
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $turn = $user->stockChatTurns()->create([
            'instrument_id' => $instrument->id,
            'provider_type' => 'pending',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'question' => '技術面如何？',
            'metadata' => [],
            'data_as_of' => now(),
        ]);

        $settingId = $setting->id;
        $setting->delete();

        Http::preventStrayRequests();
        (new RunStockChatReply($turn->id, $settingId, 'llama3.1'))->handle(
            app(StockChatService::class),
            app(LlmProviderFactory::class),
        );

        $fresh = $turn->fresh();
        $this->assertSame(AnalysisStatus::Failed, $fresh->status);
        $this->assertNotEmpty($fresh->answer);
    }

    public function test_clearing_history_removes_only_this_instruments_turns(): void
    {
        $user = User::factory()->create();
        $this->makeSetting($user);
        $a = Instrument::factory()->create(['symbol' => '2337.TW']);
        $b = Instrument::factory()->create(['symbol' => '2330.TW']);
        $this->fakeAnswer('回答');

        $this->actingAs($user)->post("/stocks/{$a->id}/chat", ['question' => 'A 的問題']);
        $this->actingAs($user)->post("/stocks/{$b->id}/chat", ['question' => 'B 的問題']);

        $this->actingAs($user)->delete("/stocks/{$a->id}/chat")->assertRedirect();

        $this->assertDatabaseCount('stock_chat_turns', 1);
        $this->assertSame('B 的問題', StockChatTurn::query()->sole()->question);
    }
}
