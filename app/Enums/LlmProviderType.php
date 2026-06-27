<?php

namespace App\Enums;

enum LlmProviderType: string
{
    // OpenAI-compatible transport (one client handles all of these).
    case OpenAI = 'openai';
    case OpenRouter = 'openrouter';
    case DeepSeek = 'deepseek';
    case Zeabur = 'zeabur';
    case Ollama = 'ollama';
    case LlamaCpp = 'llamacpp';
    case LmStudio = 'lmstudio';
    case OpenAICompatible = 'openai_compatible';

    // Gemini transport (Gemini == Google AI Studio).
    case Gemini = 'gemini';

    // Anthropic transport (Claude).
    case Anthropic = 'anthropic';

    public function defaultBaseUrl(): ?string
    {
        return match ($this) {
            self::OpenAI => 'https://api.openai.com/v1',
            self::OpenRouter => 'https://openrouter.ai/api/v1',
            self::DeepSeek => 'https://api.deepseek.com/v1',
            self::Ollama => 'http://localhost:11434/v1',
            self::LlamaCpp => 'http://localhost:8080/v1',
            self::LmStudio => 'http://localhost:1234/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta',
            self::Anthropic => 'https://api.anthropic.com',
            self::Zeabur, self::OpenAICompatible => null, // user must supply their endpoint
        };
    }

    public function isGemini(): bool
    {
        return $this === self::Gemini;
    }

    public function isAnthropic(): bool
    {
        return $this === self::Anthropic;
    }
}
