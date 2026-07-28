<?php

namespace App\Services\Llm;

use App\Contracts\LlmProvider;
use App\Data\LlmResponseData;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class AnthropicLlmProvider implements LlmProvider
{
    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * 連線階段的逾時，與整體回應逾時分開。整體逾時有 120 秒下限（供本地
     * thinking model 思考），該下限也讓「base_url 指向不可路由位址」變成佔用
     * worker 兩分鐘的手段；合法慢速模型是連得上但回得慢，黑洞位址卡在連線階段。
     */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /** 回應大小上限。baseUrl 使用者可控，不設限等於允許讀到 memory_limit。 */
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

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
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withOptions([
                'allow_redirects' => false,
                'on_headers' => $this->rejectOversizedResponse(...),
            ])
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

    /** 收到標頭時即依 Content-Length 中止過大的回應；等 body 讀完才檢查已來不及。 */
    private function rejectOversizedResponse(ResponseInterface $response): void
    {
        $length = (int) ($response->getHeaderLine('Content-Length') ?: 0);

        if ($length > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('LLM response exceeds the allowed size limit.');
        }
    }
}
