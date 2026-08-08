<?php

namespace Tests\Feature\Llm;

use App\Services\Llm\AnthropicLlmProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AnthropicLlmProviderTest extends TestCase
{
    public function test_completes_against_anthropic_messages_api(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => '參考分析：留意關稅與匯率風險。']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ], 200),
        ]);

        $provider = new AnthropicLlmProvider('https://api.anthropic.com', 'sk-ant-123', 60, 0.2, 1024);
        $response = $provider->complete('claude-sonnet-4-6', 'PROMPT_BODY');

        $this->assertSame('anthropic', $response->provider);
        $this->assertSame('claude-sonnet-4-6', $response->model);
        $this->assertStringContainsString('參考分析', $response->content);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'sk-ant-123')
                && $request->hasHeader('anthropic-version')
                && $request['model'] === 'claude-sonnet-4-6'
                && $request['max_tokens'] === 1024
                && $request['messages'][0]['content'] === 'PROMPT_BODY';
        });
    }

    /**
     * 系統指令要走 Messages API 的頂層 system 參數。
     *
     * 併進 user message 等於讓指令與未受信任的使用者輸入混在同一段文字裡，
     * prompt injection 的門檻會直接掉到「使用者會不會打字」。
     */
    public function test_system_prompt_is_sent_in_the_native_field(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'ok']],
            ], 200),
        ]);

        $provider = new AnthropicLlmProvider('https://api.anthropic.com', 'sk-ant-123', 60, 0.2, 1024);
        $provider->complete('claude-sonnet-4-6', 'PROMPT_BODY', 'SYSTEM_BODY');

        Http::assertSent(function ($request) {
            return $request['system'] === 'SYSTEM_BODY'
                && $request['messages'][0]['content'] === 'PROMPT_BODY'
                && count($request['messages']) === 1;
        });
    }

    public function test_system_field_is_absent_when_no_system_prompt_is_given(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'ok']],
            ], 200),
        ]);

        (new AnthropicLlmProvider('https://api.anthropic.com', 'k'))->complete('claude-sonnet-4-6', 'PROMPT_BODY');

        Http::assertSent(fn ($request) => ! array_key_exists('system', $request->data()));
    }

    public function test_throws_on_http_error(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(['error' => 'bad'], 401),
        ]);

        $provider = new AnthropicLlmProvider('https://api.anthropic.com', 'k');

        $this->expectException(RuntimeException::class);
        $provider->complete('claude-sonnet-4-6', 'hello');
    }
}
