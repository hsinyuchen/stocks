# Stock Market Analysis PWA

Laravel 13 PWA foundation for Taiwan and US stock, ETF, and index analysis.

This foundation includes:

- Inertia React app shell with warm and dark themes.
- PWA manifest and service worker for static assets.
- User profiles, watchlists, stock search, and saved reference analyses.
- Technical indicators: KD, MACD, moving averages, volume-aware signal rules.
- LLM provider settings for OpenAI, Gemini, OpenRouter, Zeabur/OpenAI-compatible endpoints, Ollama, and llama.cpp.
- Python YouTube worker skeleton for future transcript cleanup and chunking.

The analysis output is reference material only. It is not guaranteed investment advice.

## Data + LLM Providers

Market data driver is controlled by `MARKET_DATA_DRIVER`:

- `live` (default): Taiwan stocks via FinMind, US stocks via Stooq, Yahoo chart fallback, cached in the `daily_prices` table and shared across all users.
- `fake`: deterministic fixtures (used by the test suite via `phpunit.xml`).

Optional `FINMIND_TOKEN` raises Taiwan-data rate limits.

LLM analysis is per-user. Each user adds one or more providers in Settings; API keys are stored encrypted. Supported types:

| Brand | Transport | Notes |
| --- | --- | --- |
| `openai`, `openrouter`, `deepseek`, `ollama`, `llamacpp`, `lmstudio` | OpenAI-compatible `/chat/completions` | Leave base URL blank to use the brand default. |
| `zeabur`, `openai_compatible` | OpenAI-compatible `/chat/completions` | Base URL is required (your own gateway `/v1` endpoint). |
| `gemini` | Gemini `:generateContent` | Same API as Google AI Studio. |
| `anthropic` | Anthropic Messages API | Claude; uses `x-api-key` + `anthropic-version`. |

Local Ollama quick start:

```powershell
ollama pull llama3.1
ollama serve
```

Then add an `ollama` provider in Settings with model `llama3.1` and a blank base URL.

## Local Setup

```powershell
composer install
npm install
Copy-Item .env.example .env -Force
if (!(Test-Path database\database.sqlite)) { New-Item -ItemType File database\database.sqlite }
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

Open the app at the URL printed by `php artisan serve`.

## Development

```powershell
php artisan test
npm run build
```

The app currently uses fake market, news, and LLM providers. Real data vendors and real LLM calls are intentionally deferred behind provider contracts.

## LLM Provider Examples

The settings page stores API keys encrypted at rest. These examples show the values to enter or mirror in environment configuration when real providers are wired.

OpenRouter:

```env
LLM_PROVIDER=openrouter
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_API_KEY=your-key
OPENROUTER_MODEL=openai/gpt-4.1-mini
```

Zeabur OpenAI-compatible endpoint:

```env
LLM_PROVIDER=openai_compatible
LLM_BASE_URL=https://your-llm-gateway.zeabur.app/v1
LLM_API_KEY=your-key
LLM_MODEL=your-model-name
```

Remote Ollama on another computer:

```env
LLM_PROVIDER=ollama
OLLAMA_BASE_URL=http://192.168.1.50:11434/v1
OLLAMA_MODEL=llama3.1:8b
```

Remote llama.cpp on another computer:

```env
LLM_PROVIDER=llamacpp
LLAMACPP_BASE_URL=http://192.168.1.50:8080/v1
LLAMACPP_MODEL=local-model
```

Keep remote local LLM endpoints on LAN, VPN, or an authenticated HTTPS reverse proxy. Do not expose unauthenticated local model servers directly to the public internet.

## Python YouTube Worker

The Python worker currently exposes a fake contract payload:

```powershell
python scripts\youtube_worker.py --fake
```

Expected output is JSON with one normalized YouTube item containing `source`, `title`, `summary`, `topic`, `related_symbols`, and `published_at`.

Future work can replace `--fake` with transcript retrieval, cleanup, chunking, and queue integration while keeping the same normalized payload contract.

## Verification

```powershell
php artisan test
npm run build
python scripts\youtube_worker.py --fake
```

Expected:

- PHPUnit passes.
- Vite build succeeds.
- Python command returns JSON with one normalized YouTube item.

## Current Limits

- Market/news/LLM providers are fake implementations.
- Stock analysis is saved as reference analysis only.
- Watchlists can add instruments that already exist in the platform instrument table; stock search creates provider-derived instruments.
- Real YouTube ingestion, RSS/news ingestion, provider jobs, and deployment automation are later tasks.
