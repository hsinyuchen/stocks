<?php

namespace App\Services\Llm;

use App\Contracts\LlmProvider;
use App\Data\LlmResponseData;
use App\Exceptions\LlmRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

class OpenAiCompatibleLlmProvider implements LlmProvider
{
    /**
     * 連線階段的逾時，與整體回應逾時分開。
     *
     * 整體逾時之所以有 120 秒下限，是為了讓本地 thinking model 有時間思考；
     * 但那個下限也讓「把 base_url 指向不可路由位址」變成佔用 worker 兩分鐘的
     * 手段。合法的慢速模型是「連得上但回得慢」，黑洞位址則卡在連線階段——
     * 分開設定就能同時滿足兩者。
     */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /** 回應大小上限。baseUrl 使用者可控，不設限等於允許讀到 memory_limit。 */
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly string $providerType,
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds = 60,
        private readonly float $temperature = 0.2,
        private readonly int $maxTokens = 1200,
    ) {}

    /**
     * 在收到標頭時就依 Content-Length 中止過大的回應。
     *
     * 這是 Guzzle 提供的唯一早期中止點：等到 body 讀完才檢查已經來不及，
     * 記憶體早就被吃掉了。上游不給 Content-Length 時無從判斷，只能放行。
     */
    private function rejectOversizedResponse(ResponseInterface $response): void
    {
        $length = (int) ($response->getHeaderLine('Content-Length') ?: 0);

        if ($length > self::MAX_RESPONSE_BYTES) {
            throw LlmRequestException::oversized($this->providerType, self::MAX_RESPONSE_BYTES, $length);
        }
    }

    public function complete(string $model, string $prompt): LlmResponseData
    {
        $endpoint = rtrim($this->baseUrl, '/').'/chat/completions';

        // 關閉 redirect：baseUrl 由使用者自填，允許跟隨跳轉等於把端點的控制權
        // 交給對方主機，任何位址檢查都能被一次 302 繞過。正規的 LLM API 不跳轉。
        $request = Http::timeout($this->timeoutSeconds)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withOptions([
                'allow_redirects' => false,
                'on_headers' => $this->rejectOversizedResponse(...),
            ])
            ->acceptJson()
            ->asJson();

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        // 連線層失敗（逾時、DNS、拒絕連線）與 HTTP 錯誤碼要分開歸因，使用者
        // 該做的事完全不同：前者是等或改 base_url，後者是改金鑰或模型名。
        try {
            $response = $request->post($endpoint, [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $this->temperature,
                'max_tokens' => $this->maxTokens,
            ]);
        } catch (ConnectionException $exception) {
            throw LlmRequestException::fromConnection($this->providerType, $exception);
        }

        if ($response->failed()) {
            throw LlmRequestException::fromStatus($this->providerType, $response->status());
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
            throw LlmRequestException::emptyContent($this->providerType);
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
