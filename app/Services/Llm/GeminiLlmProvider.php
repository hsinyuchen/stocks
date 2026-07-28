<?php

namespace App\Services\Llm;

use App\Contracts\LlmProvider;
use App\Data\LlmResponseData;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiLlmProvider implements LlmProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds = 60,
        private readonly float $temperature = 0.2,
        private readonly int $maxTokens = 1200,
    ) {}

    public function complete(string $model, string $prompt): LlmResponseData
    {
        $endpoint = rtrim($this->baseUrl, '/')."/models/{$model}:generateContent?key=".urlencode((string) $this->apiKey);

        // 關閉 redirect：baseUrl 使用者可控，且金鑰帶在 query string 上，
        // 跟隨跳轉會把整條含金鑰的 URL 交給跳轉目標。
        $response = Http::timeout($this->timeoutSeconds)
            ->withOptions(['allow_redirects' => false])
            ->acceptJson()
            ->asJson()
            ->post($endpoint, [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => $this->temperature,
                    'maxOutputTokens' => $this->maxTokens,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Gemini request failed with status {$response->status()}.");
        }

        $content = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($content) || $content === '') {
            throw new RuntimeException('Gemini response did not contain candidate text.');
        }

        return new LlmResponseData(
            provider: 'gemini',
            model: $model,
            content: $content,
            metadata: [
                'usage' => $response->json('usageMetadata') ?? [],
                'status' => $response->status(),
            ],
        );
    }
}
