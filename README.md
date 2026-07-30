# Stock Market Analysis PWA

Laravel 13 PWA foundation for Taiwan and US stock, ETF, and index analysis.

This foundation includes:

- Inertia React app shell with warm and dark themes.
- PWA manifest and service worker for static assets.
- User profiles, watchlists, stock search, and saved reference analyses.
- Portfolio tracking: average-cost holdings with unrealized P&L and return %, grouped by currency (no FX conversion).
- Technical indicators: KD, MACD, RSI, Bollinger Bands, OBV, moving averages (MA5/20/60). The per-stock signal stance (`SignalEngine`) scores KD, MACD histogram, and MA5-vs-MA20 only; volume enters analysis through the screener's volume-surge rule, not the stance.
- Taiwan fundamentals on the stock page (FinMind): P/E, P/B, dividend yield, TTM EPS/ROE, monthly revenue with YoY. Cached in a shared table with a short negative-cache TTL.
- Taiwan institutional flows (FinMind `TaiwanStockInstitutionalInvestorsBuySell`): daily net buy/sell for foreign investors (incl. foreign dealers), investment trusts, and dealers (self + hedging books). Stored in shares — not lots — in the shared `chip_flows` table, cached per trading day with failure throttling. Surfaced on the stock page and fed into `SignalEngine` as a **separate** dimension: `stance`/`score` stay purely technical, and the flows produce their own `chip` block plus an `alignment` of `confirm`/`diverge`/`none`. Divergence (weak price with foreign buying, or strong price with foreign selling) is the read worth having; merging flows into the technical score would just double-count the same direction.
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

## Deployment

Deploying to a cloud host (MySQL, cPanel shared hosting or a plain VPS): see [`../docs/DEPLOY-knownhost.md`](../docs/DEPLOY-knownhost.md). Moving hosts / restoring backups: see [`../MIGRATION.md`](../MIGRATION.md).

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

Every external dependency sits behind a contract in `app/Contracts/`, bound in `app/Providers/AppServiceProvider.php`. The test suite is pinned to the fake implementations via `phpunit.xml` (`MARKET_DATA_DRIVER=fake`, `NEWS_DRIVER=fake`, in-memory sqlite), so tests never touch a real vendor. Development and production use the real providers described above.

## News maintenance

新聞的領域、相關性與關聯個股是**寫入時**計算並存進資料庫的，不是查詢時即時算。
因此修改 `config/news.php` 的 `domains` / `irrelevant` / `symbols` / `transmission`
之後，只有之後抓進來的新聞會套用新規則，既有資料仍是舊標籤。

改完關鍵字後手動重跑一次：

```powershell
php artisan news:reclassify --dry-run   # 先看會變更多少筆
php artisan news:reclassify             # 實際寫入
```

刻意不排程：主機沒有 cron，且這是「改了設定才需要跑」的維護動作，定時執行沒有意義。

`related_symbols` 採聯集，重跑只新增不移除——既有值可能來自 provider（鉅亨網 JSON
直接附代號）或個股新聞抓取，不是分類器判出的。

### 補傳導鏈規則

傳導鏈（`config/news.php` 的 `transmission`）把「事件」連到「產業」再連到「個股」。
規則是人工維護的，覆蓋率靠持續補充。用資料決定補什麼，不要憑印象：

```powershell
php artisan news:transmission-gaps                  # 覆蓋率 + 未覆蓋新聞的高頻詞
php artisan news:transmission-gaps --domain=energy  # 只看單一領域
```

指令會列出未被任何規則覆蓋的新聞裡最常出現的詞。排名靠前者代表「每天都在發生
但系統看不懂」的主題，優先補。補完再跑一次確認覆蓋率真的上升。

規則格式：

```php
[
    'key' => 'natural_disaster',           // 唯一鍵
    'label' => '天災與供應鏈中斷',          // UI 顯示名稱
    'when' => [
        'keywords' => ['地震', 'earthquake'],  // 任一命中即觸發
        'domains' => [],                       // 需同時命中的領域；留空 = 不限
    ],
    'chain' => ['第一段因果', '第二段', '第三段'],   // 逐段說明傳導路徑
    'sectors' => [
        ['name' => '航運', 'direction' => 'positive', 'symbols' => ['2603.TW']],
    ],
],
```

`domains` 限制用來防誤觸發：「關稅」出現在餐飲新聞裡不該啟動半導體出口管制的
傳導鏈。關鍵字與領域比對共用分類器的規則（ASCII 走詞邊界含複數、CJK 走子字串）。

傳導鏈是**讀取時計算**的，不存資料庫——改完設定立即生效，不需要跑 `news:reclassify`。

代表個股清單為人工維護，系統不會驗證代號是否存在，新增前請自行確認。

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

- **Prices are not adjusted for splits or dividends.** Yahoo's raw `close` and FinMind's daily rows are stored as-is in `daily_prices`; no adjusted-close, split, or ex-dividend handling exists. A split or ex-dividend date leaves a real gap in the series, which distorts MA/KD/MACD/RSI across the surrounding window. Re-fetching overwrites history (`updateOrCreate` on `instrument_id + priced_at`), so the fix for a polluted symbol is to force a refresh — but the underlying adjustment gap remains.
- `daily_prices` has no provenance column. A single instrument's history can mix rows written by different upstreams (`RoutingMarketDataProvider` silently falls back to Yahoo when the primary fails), and those upstreams do not share an adjustment convention.
- Indicator warm-up is not truncated for MACD. `series()` returns non-null `macd`/`signal`/`histogram` from the first bar, but `emaSeries()` seeds EMA with the first value rather than an SMA, so the first ~50 bars are seeding artifacts. The screener's `BaseRule::MIN_BARS = 30` is below what MACD needs to converge, while MA/RSI correctly emit `null` during their warm-up.
- `SignalEngine` scores only KD direction, MACD histogram sign, and MA5-vs-MA20 — three collinear price-momentum reads, each an unweighted ±1 with no magnitude threshold. It does not use volume, RSI, Bollinger Bands, or OBV even though `series()` computes them.
- Portfolio cost basis is raw average cost. It excludes fees and taxes and is not adjusted for splits, stock dividends, or cash dividends, so `return_pct` drifts from broker figures after any corporate action.
- Stock analysis is saved as reference analysis only.
- Watchlists can add instruments that already exist in the platform instrument table; stock search creates provider-derived instruments.
- Real YouTube ingestion (transcript retrieval, chunking, queue integration) is still a skeleton behind `--fake`.
