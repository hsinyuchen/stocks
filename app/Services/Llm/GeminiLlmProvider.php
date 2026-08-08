<?php

namespace App\Services\Llm;

use App\Contracts\LlmProvider;
use App\Data\LlmResponseData;
use App\Exceptions\LlmRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

class GeminiLlmProvider implements LlmProvider
{
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

    public function complete(string $model, string $prompt, ?string $system = null): LlmResponseData
    {
        $endpoint = rtrim($this->baseUrl, '/')."/models/{$model}:generateContent?key=".urlencode((string) $this->apiKey);

        // 關閉 redirect：baseUrl 使用者可控，且金鑰帶在 query string 上，
        // 跟隨跳轉會把整條含金鑰的 URL 交給跳轉目標。
        $request = Http::timeout($this->timeoutSeconds)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withOptions([
                'allow_redirects' => false,
                'on_headers' => $this->rejectOversizedResponse(...),
            ])
            ->acceptJson()
            ->asJson();

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => $this->temperature,
                'maxOutputTokens' => $this->maxTokens,
            ],
        ];

        // Gemini 的系統指令是頂層 systemInstruction，格式與 contents 相同但沒有 role。
        if ($system !== null && $system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        // 連線層失敗與 HTTP 錯誤碼分開歸因，兩者的處理方式不同。
        try {
            $response = $request->post($endpoint, $payload);
        } catch (ConnectionException $exception) {
            throw LlmRequestException::fromConnection('gemini', $exception);
        }

        if ($response->failed()) {
            throw LlmRequestException::fromStatus('gemini', $response->status());
        }

        $content = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($content) || $content === '') {
            throw LlmRequestException::emptyContent('gemini');
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

    /** 收到標頭時即依 Content-Length 中止過大的回應；等 body 讀完才檢查已來不及。 */
    private function rejectOversizedResponse(ResponseInterface $response): void
    {
        $length = (int) ($response->getHeaderLine('Content-Length') ?: 0);

        if ($length > self::MAX_RESPONSE_BYTES) {
            throw LlmRequestException::oversized('gemini', self::MAX_RESPONSE_BYTES, $length);
        }
    }
}
