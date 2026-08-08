<?php

namespace Tests\Feature\Llm;

use App\Services\Llm\GeminiLlmProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiLlmProviderTest extends TestCase
{
    public function test_completes_against_gemini_endpoint(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => '參考分析：留意半導體需求。']]]],
                ],
                'usageMetadata' => ['totalTokenCount' => 88],
            ], 200),
        ]);

        $provider = new GeminiLlmProvider(
            'https://generativelanguage.googleapis.com/v1beta',
            'gemini-key-123',
            60,
            0.2,
            1200,
        );

        $response = $provider->complete('gemini-2.5-flash', 'PROMPT_BODY');

        $this->assertSame('gemini', $response->provider);
        $this->assertSame('gemini-2.5-flash', $response->model);
        $this->assertStringContainsString('參考分析', $response->content);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/models/gemini-2.5-flash:generateContent')
                && str_contains($request->url(), 'key=gemini-key-123')
                && $request['contents'][0]['parts'][0]['text'] === 'PROMPT_BODY'
                && $request['generationConfig']['maxOutputTokens'] === 1200;
        });
    }

    /** 系統指令要走頂層 systemInstruction，不得併進 contents 的使用者文字。 */
    public function test_system_prompt_is_sent_in_the_native_field(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
            ], 200),
        ]);

        $provider = new GeminiLlmProvider('https://generativelanguage.googleapis.com/v1beta', 'k');
        $provider->complete('gemini-2.5-flash', 'PROMPT_BODY', 'SYSTEM_BODY');

        Http::assertSent(function ($request) {
            return $request['systemInstruction']['parts'][0]['text'] === 'SYSTEM_BODY'
                && $request['contents'][0]['parts'][0]['text'] === 'PROMPT_BODY';
        });
    }

    public function test_system_instruction_is_absent_when_no_system_prompt_is_given(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
            ], 200),
        ]);

        (new GeminiLlmProvider('https://generativelanguage.googleapis.com/v1beta', 'k'))
            ->complete('gemini-2.5-flash', 'PROMPT_BODY');

        Http::assertSent(fn ($request) => ! array_key_exists('systemInstruction', $request->data()));
    }

    public function test_throws_on_http_error(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(['error' => 'bad'], 400),
        ]);

        $provider = new GeminiLlmProvider('https://generativelanguage.googleapis.com/v1beta', 'k');

        $this->expectException(RuntimeException::class);
        $provider->complete('gemini-2.5-flash', 'hello');
    }
}
