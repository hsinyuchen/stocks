<?php

namespace App\Services\Llm;

use App\Contracts\LlmProvider;
use App\Data\LlmResponseData;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicLlmProvider implements LlmProvider
{
    private const ANTHROPIC_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds = 60,
        private readonly float $temperature = 0.2,
        private readonly int $maxTokens = 1200,
    ) {}

    public function complete(string $model, string $prompt): LlmResponseData
    {
        $endpoint = rtrim($this->baseUrl, '/').'/v1/messages';

        // 關閉 redirect：baseUrl 使用者可控，且此請求帶著 x-api-key。
        // 跟隨跳轉會把金鑰送到跳轉目標，等於憑證外洩原語。
        $response = Http::timeout($this->timeoutSeconds)
            ->withOptions(['allow_redirects' => false])
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-api-key' => (string) $this->apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ])
            ->post($endpoint, [
                'model' => $model,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Anthropic request failed with status {$response->status()}.");
        }

        $content = $response->json('content.0.text');

        if (! is_string($content) || $content === '') {
            throw new RuntimeException('Anthropic response did not contain text content.');
        }

        return new LlmResponseData(
            provider: 'anthropic',
            model: (string) ($response->json('model') ?? $model),
            content: $content,
            metadata: [
                'usage' => $response->json('usage') ?? [],
                'status' => $response->status(),
            ],
        );
    }
}
