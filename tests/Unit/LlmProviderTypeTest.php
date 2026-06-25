<?php

namespace Tests\Unit;

use App\Enums\LlmProviderType;
use PHPUnit\Framework\TestCase;

class LlmProviderTypeTest extends TestCase
{
    public function test_default_base_urls(): void
    {
        $this->assertSame('https://api.openai.com/v1', LlmProviderType::OpenAI->defaultBaseUrl());
        $this->assertSame('https://openrouter.ai/api/v1', LlmProviderType::OpenRouter->defaultBaseUrl());
        $this->assertSame('https://api.deepseek.com/v1', LlmProviderType::DeepSeek->defaultBaseUrl());
        $this->assertSame('http://localhost:11434/v1', LlmProviderType::Ollama->defaultBaseUrl());
        $this->assertSame('http://localhost:8080/v1', LlmProviderType::LlamaCpp->defaultBaseUrl());
        $this->assertSame('http://localhost:1234/v1', LlmProviderType::LmStudio->defaultBaseUrl());
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta', LlmProviderType::Gemini->defaultBaseUrl());
        $this->assertSame('https://api.anthropic.com', LlmProviderType::Anthropic->defaultBaseUrl());
        $this->assertNull(LlmProviderType::Zeabur->defaultBaseUrl());
        $this->assertNull(LlmProviderType::OpenAICompatible->defaultBaseUrl());
    }

    public function test_transport_helpers(): void
    {
        $this->assertTrue(LlmProviderType::Gemini->isGemini());
        $this->assertFalse(LlmProviderType::Anthropic->isGemini());

        $this->assertTrue(LlmProviderType::Anthropic->isAnthropic());
        $this->assertFalse(LlmProviderType::Gemini->isAnthropic());
        $this->assertFalse(LlmProviderType::Ollama->isAnthropic());
    }
}
