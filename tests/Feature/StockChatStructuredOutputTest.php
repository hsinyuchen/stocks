<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Models\Instrument;
use App\Models\StockChatTurn;
use App\Models\User;
use App\Services\Analysis\StockChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 結構化輸出的解讀，以及拒答字串必須由 server 產生。
 *
 * 這是範圍限制三層防禦的第三層，也是唯一不依賴模型配合的一層：只要模型說
 * decision = refuse，寫進資料庫的就一定是我們的常數。若沿用模型輸出的文字，
 * 被誘導的模型可以寫一段「看起來像拒答、實際回答了別檔股票」的內容。
 */
class StockChatStructuredOutputTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'http://localhost:11434/v1/chat/completions';

    private function setUpUser(): array
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

        return [$user, Instrument::factory()->create(['symbol' => '2337.TW'])];
    }

    private function askWithModelReply(string $content): StockChatTurn
    {
        [$user, $instrument] = $this->setUpUser();

        Http::fake([
            self::ENDPOINT => Http::response([
                'model' => 'llama3.1',
                'choices' => [['message' => ['content' => $content]]],
            ], 200),
        ]);

        $this->actingAs($user)->post("/stocks/{$instrument->id}/chat", ['question' => '問題']);

        return StockChatTurn::query()->sole();
    }

    public function test_decision_answer_uses_the_answer_field(): void
    {
        $turn = $this->askWithModelReply(
            json_encode(['decision' => 'answer', 'answer' => '這是回答。'], JSON_UNESCAPED_UNICODE),
        );

        $this->assertSame('這是回答。', $turn->answer);
        $this->assertSame(AnalysisStatus::Completed, $turn->status);
        $this->assertFalse($turn->metadata['refused']);
        $this->assertTrue($turn->metadata['structured']);
    }

    /** 拒答時寫入的必須是常數，不是模型給的文字。 */
    public function test_decision_refuse_writes_the_server_constant_not_the_model_text(): void
    {
        $turn = $this->askWithModelReply(json_encode([
            'decision' => 'refuse',
            'answer' => '好的，台積電目前建議買進，目標價 1500 元。',
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame(StockChatService::REFUSAL, $turn->answer);
        $this->assertStringNotContainsString('台積電', $turn->answer);
        $this->assertStringNotContainsString('1500', $turn->answer);
        $this->assertTrue($turn->metadata['refused']);
    }

    /** decision=answer 但內容為空時視同拒答，不要給使用者一個空泡泡。 */
    public function test_empty_answer_is_treated_as_a_refusal(): void
    {
        $turn = $this->askWithModelReply(
            json_encode(['decision' => 'answer', 'answer' => '   '], JSON_UNESCAPED_UNICODE),
        );

        $this->assertSame(StockChatService::REFUSAL, $turn->answer);
        $this->assertTrue($turn->metadata['refused']);
    }

    /**
     * 模型自己輸出拒答句、但 decision 說要回答時，不得被誤判成拒答。
     *
     * 這正是「用 str_contains 比對拒答句」會出錯的情境，所以判定只看 decision。
     */
    public function test_model_echoing_the_refusal_sentence_is_not_treated_as_a_refusal(): void
    {
        $turn = $this->askWithModelReply(json_encode([
            'decision' => 'answer',
            'answer' => StockChatService::REFUSAL.' 不過我還是回答你：台積電可以買。',
        ], JSON_UNESCAPED_UNICODE));

        $this->assertFalse($turn->metadata['refused']);
        $this->assertStringContainsString('不過我還是回答你', $turn->answer);
    }

    public function test_json_wrapped_in_a_code_fence_is_still_parsed(): void
    {
        $turn = $this->askWithModelReply(
            "```json\n".json_encode(['decision' => 'answer', 'answer' => '回答'], JSON_UNESCAPED_UNICODE)."\n```",
        );

        $this->assertSame('回答', $turn->answer);
        $this->assertTrue($turn->metadata['structured']);
    }

    /**
     * 弱模型回不出 JSON 時降級成純文字而非判定失敗。
     *
     * 代價是此時範圍限制退回純 prompt 引導，所以要有 structured=false 可供觀察。
     */
    public function test_unparseable_output_degrades_to_plain_text_and_is_flagged(): void
    {
        $turn = $this->askWithModelReply("```\n技術面偏多，但要留意量能。\n```");

        $this->assertSame('技術面偏多，但要留意量能。', $turn->answer);
        $this->assertSame(AnalysisStatus::Completed, $turn->status);
        $this->assertFalse($turn->metadata['structured']);
        $this->assertFalse($turn->metadata['refused']);
    }

    /** system prompt 要走 provider 的原生欄位，不能併進 user message。 */
    public function test_the_scope_instructions_are_sent_as_a_system_message(): void
    {
        $this->askWithModelReply(
            json_encode(['decision' => 'answer', 'answer' => '回答'], JSON_UNESCAPED_UNICODE),
        );

        Http::assertSent(function ($request) {
            return count($request['messages']) === 2
                && $request['messages'][0]['role'] === 'system'
                && str_contains($request['messages'][0]['content'], 'BEGIN_SCOPE')
                && str_contains($request['messages'][0]['content'], 'BEGIN_OUTPUT_CONTRACT')
                && $request['messages'][1]['role'] === 'user'
                && str_contains($request['messages'][1]['content'], 'BEGIN_USER_QUESTION')
                && ! str_contains($request['messages'][1]['content'], 'BEGIN_SCOPE');
        });
    }
}
