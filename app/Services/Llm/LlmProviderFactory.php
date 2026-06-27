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
        $timeout = max((int) $setting->timeout_seconds, 120);
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
