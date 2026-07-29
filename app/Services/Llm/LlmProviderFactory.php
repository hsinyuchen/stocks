<?php

namespace App\Services\Llm;

use App\Contracts\LlmProvider;
use App\Enums\LlmProviderType;
use App\Models\LlmProviderSetting;
use InvalidArgumentException;

class LlmProviderFactory
{
    public function make(LlmProviderSetting $setting): LlmProvider
    {
        $type = LlmProviderType::from($setting->provider_type);
        $baseUrl = $setting->base_url ?: $type->defaultBaseUrl();

        if ($baseUrl === null) {
            throw new InvalidArgumentException(
                "Provider type {$type->value} requires an explicit base URL.",
            );
        }

        $apiKey = $setting->api_key_encrypted; // decrypted by the model cast
        $temperature = (float) $setting->temperature;
        // Floors so thinking models (e.g. local Ollama) don't truncate: they
        // spend most of the budget on `reasoning` before the answer, so a low
        // max_tokens cuts off the structured JSON/analysis. The model still
        // stops early when finished, so fast cloud models are unaffected.
        //
        // 逾時下限改為可設定：它同時決定了「單次 request 最久會被佔用多久」，
        // 而受限主機的 max_execution_time 可能低到蓋不住預設值。降低它只會讓
        // 慢速模型提早被判定 timeout（畫面上有明確原因），不影響正常回應。
        $timeout = max((int) $setting->timeout_seconds, (int) config('analysis.llm_timeout_floor', 120));
        $maxTokens = max((int) $setting->max_tokens, 4096);

        if ($type->isGemini()) {
            return new GeminiLlmProvider($baseUrl, $apiKey, $timeout, $temperature, $maxTokens);
        }

        if ($type->isAnthropic()) {
            return new AnthropicLlmProvider($baseUrl, $apiKey, $timeout, $temperature, $maxTokens);
        }

        return new OpenAiCompatibleLlmProvider($type->value, $baseUrl, $apiKey, $timeout, $temperature, $maxTokens);
    }
}
