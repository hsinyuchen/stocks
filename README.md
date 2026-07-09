# Stock Market Analysis PWA

Laravel 13 PWA foundation for Taiwan and US stock, ETF, and index analysis.

This foundation includes:

- Inertia React app shell with warm and dark themes.
- PWA manifest and service worker for static assets.
- User profiles, watchlists, stock search, and saved reference analyses.
- Portfolio tracking: average-cost holdings with unrealized P&L and return %, grouped by currency (no FX conversion).
- Technical indicators: KD, MACD, RSI, Bollinger Bands, OBV, moving averages (MA5/20/60), volume-aware signal rules.
- Technical screener: preset signal rules (KD cross, MA20, MACD cross, RSI, volume surge) over a configurable universe + personal watchlists.
- Price alerts: threshold / daily-change-% / technical-signal conditions, checked passively on page visits (no cron), one-shot with manual re-arm.
- Per-symbol news via Google News RSS, fetched on stock-page visits (throttled per symbol), deduped into the shared news stream.
- Candlestick charts via [TradingView Lightweight Charts™](https://www.tradingview.com/lightweight-charts/) (Apache 2.0, attribution logo kept on the main chart): daily/weekly/monthly timeframes, toggleable indicator panes, multi-symbol normalized comparison incl. indices.
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

## Screener

技術選股器：以勾選的預設訊號規則（KD 交叉、站上/跌破 MA20、MACD 多頭交叉、RSI 超買/超賣、爆量）AND 複選過濾股票。掃描範圍 = `config/screener.php` 的內建股池 ∪ 使用者自選股。

首次使用先預載股池價格（建立/更新 `daily_prices` 快取，可重複執行）：

```powershell
php artisan screener:warm
```

股池可自行增減：編輯 `config/screener.php` 的 `universe`（`['symbol' => ..., 'name' => ...]`），新增後重跑 `php artisan screener:warm` 預載。

掃描為 on-demand + 快取優先，無 cron；首次掃到未快取的股票會即時拉取，可能較慢。分析結果僅供參考，非投資建議。

## Admin

首個管理員（部署後執行一次）：

```powershell
php artisan user:promote your-email@example.com
```

管理頁：`/admin/users`（僅 admin 可見）。可停用/啟用、升降 admin、寄密碼重設信（需配 MAIL_*）、刪除使用者、建立帳號。

關閉自助註冊：`.env` 設 `REGISTRATION_ENABLED=false`。

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
