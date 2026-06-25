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
        $timeout = (int) $setting->timeout_seconds;
        $temperature = (float) $setting->temperature;
        $maxTokens = (int) $setting->max_tokens;

        if ($type->isGemini()) {
            return new GeminiLlmProvider($baseUrl, $apiKey, $timeout, $temperature, $maxTokens);
        }

        if ($type->isAnthropic()) {
            return new AnthropicLlmProvider($baseUrl, $apiKey, $timeout, $temperature, $maxTokens);
        }

        return new OpenAiCompatibleLlmProvider($type->value, $baseUrl, $apiKey, $timeout, $temperature, $maxTokens);
    }
}
