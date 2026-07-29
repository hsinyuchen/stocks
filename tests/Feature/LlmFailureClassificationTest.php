<?php

namespace Tests\Feature;

use App\Enums\LlmFailureReason;
use App\Exceptions\LlmRequestException;
use App\Services\Llm\AnthropicLlmProvider;
use App\Services\Llm\GeminiLlmProvider;
use App\Services\Llm\OpenAiCompatibleLlmProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LLM 失敗要分類到位。
 *
 * 動機來自實際案例：ollama.com 連續 120 秒零回應、金鑰失效的 403、模型名不存在的
 * 500，三者在畫面上曾經長得一模一樣，使用者無從判斷該重試還是該改設定。
 */
class LlmFailureClassificationTest extends TestCase
{
    private function openAi(): OpenAiCompatibleLlmProvider
    {
        return new OpenAiCompatibleLlmProvider('ollama', 'http://localhost:11434/v1', null, 30, 0.2, 800);
    }

    /**
     * @return array<string, array{int, LlmFailureReason}>
     */
    public static function statusProvider(): array
    {
        return [
            '401 未授權' => [401, LlmFailureReason::Auth],
            '403 禁止' => [403, LlmFailureReason::Auth],
            '404 找不到' => [404, LlmFailureReason::ModelNotFound],
            '429 速率限制' => [429, LlmFailureReason::RateLimit],
            '400 參數錯誤' => [400, LlmFailureReason::BadRequest],
            '500 上游錯誤' => [500, LlmFailureReason::ServerError],
            '503 上游不可用' => [503, LlmFailureReason::ServerError],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_http_status_maps_to_a_reason(int $status, LlmFailureReason $expected): void
    {
        Http::fake(['http://localhost:11434/v1/chat/completions' => Http::response([], $status)]);

        try {
            $this->openAi()->complete('llama3.1', 'p');
            $this->fail('expected LlmRequestException');
        } catch (LlmRequestException $exception) {
            $this->assertSame($expected, $exception->reason);
        }
    }

    public function test_curl_timeout_is_classified_as_timeout_not_unreachable(): void
    {
        // 實際日誌中的訊息：連得上但 120 秒內一個 byte 都沒收到。
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 120002 milliseconds with 0 bytes received',
        ));

        try {
            $this->openAi()->complete('llama3.1', 'p');
            $this->fail('expected LlmRequestException');
        } catch (LlmRequestException $exception) {
            $this->assertSame(LlmFailureReason::Timeout, $exception->reason);
        }
    }

    public function test_connection_refused_is_classified_as_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 7: Failed to connect to localhost port 11434: Connection refused',
        ));

        try {
            $this->openAi()->complete('llama3.1', 'p');
            $this->fail('expected LlmRequestException');
        } catch (LlmRequestException $exception) {
            $this->assertSame(LlmFailureReason::Unreachable, $exception->reason);
        }
    }

    public function test_successful_response_without_content_is_classified_as_empty(): void
    {
        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '   ']]],
            ], 200),
        ]);

        try {
            $this->openAi()->complete('llama3.1', 'p');
            $this->fail('expected LlmRequestException');
        } catch (LlmRequestException $exception) {
            $this->assertSame(LlmFailureReason::EmptyResponse, $exception->reason);
        }
    }

    public function test_gemini_classifies_status_errors(): void
    {
        Http::fake(['*' => Http::response([], 403)]);

        $provider = new GeminiLlmProvider('https://generativelanguage.googleapis.com/v1beta', 'k', 30, 0.2, 800);

        try {
            $provider->complete('gemini-2.0-flash', 'p');
            $this->fail('expected LlmRequestException');
        } catch (LlmRequestException $exception) {
            $this->assertSame(LlmFailureReason::Auth, $exception->reason);
        }
    }

    public function test_anthropic_classifies_status_errors(): void
    {
        Http::fake(['*' => Http::response([], 429)]);

        $provider = new AnthropicLlmProvider('https://api.anthropic.com', 'k', 30, 0.2, 800);

        try {
            $provider->complete('claude-sonnet-5', 'p');
            $this->fail('expected LlmRequestException');
        } catch (LlmRequestException $exception) {
            $this->assertSame(LlmFailureReason::RateLimit, $exception->reason);
        }
    }

    public function test_every_reason_has_a_distinct_user_facing_message(): void
    {
        $messages = array_map(
            static fn (LlmFailureReason $reason): string => $reason->message(),
            LlmFailureReason::cases(),
        );

        // 分類存在的意義就是畫面上看得出差別；文案重複等於白做。
        $this->assertSame(count($messages), count(array_unique($messages)));

        foreach (LlmFailureReason::cases() as $reason) {
            $this->assertNotSame('', $reason->hint());
        }
    }
}
