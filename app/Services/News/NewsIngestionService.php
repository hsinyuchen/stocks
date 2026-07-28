<?php

namespace App\Services\News;

use App\Data\NewsItemData;
use App\Models\FeedHealth;
use App\Models\NewsItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates one ingestion run: loop the configured feeds (each wrapped in
 * its own try/catch so a single bad feed never aborts the batch), fetch via
 * RssNewsProvider, classify via NewsClassifier, dedup by url_hash, upsert into
 * news_items, then prune items older than the retention window.
 */
class NewsIngestionService
{
    public function __construct(
        private readonly RssNewsProvider $provider,
        private readonly NewsClassifier $classifier,
        private readonly ?CnyesNewsProvider $cnyes = null,
    ) {}

    /**
     * 依 feed 的 driver 選擇抓取實作。
     *
     * 預設 rss，涵蓋所有 RSS 與 Atom 來源（含 YouTube 頻道 feed）。鉅亨網沒有
     * RSS，只有 JSON API，故獨立一個 driver。
     *
     * @param  array<string, mixed>  $feed
     * @return list<NewsItemData>
     */
    private function fetchFeed(array $feed): array
    {
        return match ((string) ($feed['driver'] ?? 'rss')) {
            'cnyes' => ($this->cnyes ?? new CnyesNewsProvider)->fetch($feed),
            default => $this->provider->fetch($feed),
        };
    }

    /**
     * Run a full ingestion pass.
     *
     * @return array{feeds: array<string, int>, inserted: int, updated: int, pruned: int, health: list<array<string, mixed>>}
     */
    public function ingest(): array
    {
        $feeds = [];
        $health = [];
        $inserted = 0;
        $updated = 0;
        $cutoff = $this->freshnessCutoff();

        foreach ((array) config('news.feeds', []) as $feed) {
            $key = (string) ($feed['key'] ?? ($feed['name'] ?? ''));
            $name = (string) ($feed['name'] ?? $key);

            try {
                $items = $this->fetchFeed($feed);
            } catch (Throwable $e) {
                Log::warning('news ingest: feed failed', [
                    'feed' => $key,
                    'error' => $e->getMessage(),
                ]);

                $feeds[$key] = 0;
                $health[] = $this->recordHealth($key, $name, 0, 0, $e->getMessage());

                continue;
            }

            $count = 0;
            $fresh = 0;

            foreach ($items as $item) {
                // 封鎖來源在計入健康度前就過濾：一個 feed 若只回封鎖來源，
                // 對本站而言等同沒有內容，應該被判定為不健康。
                if ($this->isBlocked($item->source)) {
                    continue;
                }

                if ($this->upsert($item)) {
                    $inserted++;
                } else {
                    $updated++;
                }

                if ($this->isFresh($item, $cutoff)) {
                    $fresh++;
                }

                $count++;
            }

            $feeds[$key] = $count;
            $health[] = $this->recordHealth($key, $name, $count, $fresh, null);
        }

        $pruned = $this->prune();

        return [
            'feeds' => $feeds,
            'inserted' => $inserted,
            'updated' => $updated,
            'pruned' => $pruned,
            'health' => $health,
        ];
    }

    /**
     * 來源是否在封鎖清單內（名稱子字串，不分大小寫）。
     *
     * Google News 的 <source> 子元素帶原始媒體名，內容農場即由此辨識。
     */
    public function isBlocked(string $source): bool
    {
        $source = mb_strtolower(trim($source));

        if ($source === '') {
            return false;
        }

        foreach ((array) config('news.blocked_sources', []) as $blocked) {
            $blocked = mb_strtolower(trim((string) $blocked));

            if ($blocked !== '' && str_contains($source, $blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 健康度的「新鮮」判定起點。
     *
     * 與 retention_days 無關：保留窗口決定資料存多久，這裡回答「上游最近還有
     * 沒有產出內容」。用保留窗口當基準會在 retention 拉長後失去偵測力。
     */
    private function freshnessCutoff(): Carbon
    {
        return Carbon::now()->subDays((int) config('news.health.fresh_within_days', 7));
    }

    /** 沒有發布時間時無從判斷新舊，保守視為新鮮，避免誤判 feed 已死。 */
    private function isFresh(NewsItemData $item, Carbon $cutoff): bool
    {
        if ($item->publishedAt === '') {
            return true;
        }

        try {
            return Carbon::parse($item->publishedAt)->greaterThanOrEqualTo($cutoff);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * 記錄一次抓取結果。連續多次沒有新鮮項目才算不健康——單次為 0 可能只是
     * 該來源當下沒有新內容，不足以判定 feed 已死。
     *
     * @return array<string, mixed>
     */
    private function recordHealth(string $key, string $name, int $items, int $fresh, ?string $error): array
    {
        $row = FeedHealth::query()->firstOrNew(['key' => $key]);

        $row->name = $name;
        $row->last_item_count = $items;
        $row->last_fresh_count = $fresh;
        $row->last_run_at = Carbon::now();
        $row->last_error = $error;
        $row->consecutive_stale_runs = $fresh > 0 ? 0 : $row->consecutive_stale_runs + 1;

        if ($fresh > 0) {
            $row->last_fresh_at = Carbon::now();
        }

        $row->save();

        if ($row->isUnhealthy()) {
            Log::warning('news ingest: feed produced no fresh items', [
                'feed' => $key,
                'consecutive_stale_runs' => $row->consecutive_stale_runs,
                'last_item_count' => $items,
                'last_error' => $error,
            ]);
        }

        return [
            'key' => $key,
            'name' => $name,
            'items' => $items,
            'fresh' => $fresh,
            'error' => $error,
            'stale_runs' => $row->consecutive_stale_runs,
            'unhealthy' => $row->isUnhealthy(),
        ];
    }

    /**
     * 分類並寫入一則新聞（url_hash 去重）。
     *
     * related_symbols 採聯集語義：
     * 既有 DB 值 ∪ provider 隨項目帶來的 ∪ classifier 判出 ∪ $extraSymbols。
     *
     * provider 帶來的那份不可省略：鉅亨網 JSON API 直接附結構化代號，品質高於
     * 關鍵字猜測（classifier 的字典認不得「三商壽」這類公司名）。
     * 同一 URL 先被 A symbol 寫入、後被 B symbol 寫入時，A 不會被覆蓋——
     * relatedNews(A) 的可見性因此不受後續寫入影響。
     *
     * @param  list<string>  $extraSymbols
     * @return bool true = 新增；false = 更新既有，或來源被封鎖而未寫入
     */
    public function upsert(NewsItemData $item, array $extraSymbols = []): bool
    {
        // 封鎖檢查放在 upsert 而非只在 ingest() 迴圈：個股新聞（SymbolNewsService）
        // 直接呼叫本方法，只擋 ingest() 的話內容農場照樣能從個股頁進到共用新聞流，
        // 而個股 Google News 正是內容農場最主要的來源。
        if ($this->isBlocked($item->source)) {
            return false;
        }

        $classification = $this->classifier->classify($item->title, $item->summary);

        // 連結協定過濾：url 直接來自外部 RSS 的 <link>，全程原樣入庫再輸出到
        // <a href>。上游若被劫持而給出 javascript: 或 data:text/html，React 對
        // href 只會在 dev console 警告，production 仍會渲染成可點擊的 XSS。
        $url = $this->safeUrl($item->url);

        $hash = sha1($url !== ''
            ? $url
            : $item->source.'|'.$item->title);

        $existing = NewsItem::query()->where('url_hash', $hash)->first();

        // 聯集：先取既有 related_symbols，避免後續其他 symbol 的寫入覆蓋前一個 symbol 的標記
        $symbols = array_values(array_unique(array_merge(
            $existing?->related_symbols ?? [],
            $item->relatedSymbols,
            $classification['symbols'],
            $extraSymbols,
        )));

        NewsItem::updateOrCreate(
            ['url_hash' => $hash],
            [
                'source' => $item->source,
                'title' => $item->title,
                'summary' => $item->summary,
                'url' => $url,
                'published_at' => $item->publishedAt !== '' ? $item->publishedAt : null,
                'language' => $item->language,
                'market' => $item->market,
                'topic' => $item->topic,
                'domain' => $classification['domain'],
                'domains' => $classification['domains'],
                // 相關性依 classifier 判定的領域，但 symbols 用聯集後的結果：
                // 個股 Google News 帶進來的 $extraSymbols 也算正向訊號。
                'relevant' => $classification['relevant'] || $symbols !== [],
                'related_symbols' => $symbols,
            ],
        );

        return $existing === null;
    }

    /**
     * Remove items older than the retention window. Falls back to created_at
     * when published_at is null.
     */
    private function prune(): int
    {
        $cutoff = Carbon::now()->subDays((int) config('news.retention_days', 30));

        return NewsItem::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('published_at', '<', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('published_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->delete();
    }

    /**
     * 只放行 http/https 的連結，其餘一律清空。
     *
     * 清空而非整筆丟棄：新聞內容本身仍有價值，只是連結不可信；前端對空 url
     * 已有處理（不渲染成連結）。
     */
    private function safeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }
}
