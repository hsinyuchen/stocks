<?php

namespace App\Services\Llm;

use App\Contracts\LlmProvider;
use App\Data\LlmResponseData;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiCompatibleLlmProvider implements LlmProvider
{
    public function __construct(
        private readonly string $providerType,
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds = 60,
        private readonly float $temperature = 0.2,
        private readonly int $maxTokens = 1200,
    ) {}

    public function complete(string $model, string $prompt): LlmResponseData
    {
        $endpoint = rtrim($this->baseUrl, '/').'/chat/completions';

        $request = Http::timeout($this->timeoutSeconds)->acceptJson()->asJson();

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post($endpoint, [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("LLM request to {$this->providerType} failed with status {$response->status()}.");
        }

        $content = $response->json('choices.0.message.content');

        // Some local/thinking models (e.g. via Ollama) put the answer in
        // `reasoning` and leave `content` empty — especially when the token
        // budget is spent reasoning. Fall back to reasoning so these models
        // still return usable text instead of failing.
        if (! is_string($content) || trim($content) === '') {
            $reasoning = $response->json('choices.0.message.reasoning');

            if (is_string($reasoning) && trim($reasoning) !== '') {
                $content = $reasoning;
            }
        }

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException("LLM response from {$this->providerType} did not contain message content.");
        }

        return new LlmResponseData(
            provider: $this->providerType,
            model: (string) ($response->json('model') ?? $model),
            content: $content,
            metadata: [
                'usage' => $response->json('usage') ?? [],
                'status' => $response->status(),
            ],
        );
    }
}
