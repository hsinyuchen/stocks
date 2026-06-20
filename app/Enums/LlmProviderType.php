<?php

namespace App\Enums;

enum LlmProviderType: string
{
    case OpenAI = 'openai';
    case Gemini = 'gemini';
    case OpenRouter = 'openrouter';
    case OpenAICompatible = 'openai_compatible';
    case Ollama = 'ollama';
    case LlamaCpp = 'llamacpp';
}
