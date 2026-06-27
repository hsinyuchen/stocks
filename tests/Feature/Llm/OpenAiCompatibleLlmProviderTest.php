<?php

namespace Tests\Feature\Llm;

use App\Services\Llm\OpenAiCompatibleLlmProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAiCompatibleLlmProviderTest extends TestCase
{
    public function test_completes_against_ollama_endpoint_without_api_key(): void
    {
        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response([
                'model' => 'llama3.1',
                'choices' => [['message' => ['content' => '參考分析：偏多但需確認風險。']]],
                'usage' => ['total_tokens' => 42],
            ], 200),
        ]);

        $provider = new OpenAiCompatibleLlmProvider('ollama', 'http://localhost:11434/v1', null, 30, 0.2, 800);
        $response = $provider->complete('llama3.1', 'PROMPT_BODY');

        $this->assertSame('ollama', $response->provider);
        $this->assertSame('llama3.1', $response->model);
        $this->assertStringContainsString('參考分析', $response->content);
        $this->assertSame(42, $response->metadata['usage']['total_tokens']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:11434/v1/chat/completions'
                && $request['model'] === 'llama3.1'
                && $request['messages'][0]['content'] === 'PROMPT_BODY'
                && $request['temperature'] === 0.2
                && $request['max_tokens'] === 800
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_sends_bearer_token_when_api_key_present(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4.1-mini',
                'choices' => [['message' => ['content' => 'ok']]],
            ], 200),
        ]);

        $provider = new OpenAiCompatibleLlmProvider('openai', 'https://api.openai.com/v1', 'sk-test-123', 60, 0.2, 1200);
        $provider->complete('gpt-4.1-mini', 'hello');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-test-123'));
    }

    public function test_throws_on_http_error(): void
    {
        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response(['error' => 'boom'], 500),
        ]);

        $provider = new OpenAiCompatibleLlmProvider('ollama', 'http://localhost:11434/v1', null);

        $this->expectException(RuntimeException::class);
        $provider->complete('llama3.1', 'hello');
    }

    public function test_throws_when_content_missing(): void
    {
        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response(['choices' => []], 200),
        ]);

        $provider = new OpenAiCompatibleLlmProvider('ollama', 'http://localhost:11434/v1', null);

        $this->expectException(RuntimeException::class);
        $provider->complete('llama3.1', 'hello');
    }

    public function test_falls_back_to_reasoning_when_content_is_empty(): void
    {
        // Thinking models (Ollama) often leave content empty and put the answer
        // in `reasoning`, especially when the token budget is spent reasoning.
        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response([
                'model' => 'gemma4:12b',
                'choices' => [['message' => [
                    'content' => '',
                    'reasoning' => '參考分析：台積電為晶圓代工龍頭，留意先進製程需求。',
                ]]],
            ], 200),
        ]);

        $provider = new OpenAiCompatibleLlmProvider('ollama', 'http://localhost:11434/v1', null);
        $response = $provider->complete('gemma4:12b', 'hello');

        $this->assertStringContainsString('參考分析', $response->content);
    }
}
