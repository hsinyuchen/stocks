<?php

namespace App\Services\News;

use App\Data\NewsItemData;
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
    ) {}

    /**
     * Run a full ingestion pass.
     *
     * @return array{feeds: array<string, int>, inserted: int, updated: int, pruned: int}
     */
    public function ingest(): array
    {
        $feeds = [];
        $inserted = 0;
        $updated = 0;

        foreach ((array) config('news.feeds', []) as $feed) {
            $key = (string) ($feed['key'] ?? ($feed['name'] ?? ''));

            try {
                $items = $this->provider->fetch($feed);
            } catch (Throwable $e) {
                Log::warning('news ingest: feed failed', [
                    'feed' => $key,
                    'error' => $e->getMessage(),
                ]);

                $feeds[$key] = 0;

                continue;
            }

            $count = 0;

            foreach ($items as $item) {
                if ($this->upsert($item)) {
                    $inserted++;
                } else {
                    $updated++;
                }

                $count++;
            }

            $feeds[$key] = $count;
        }

        $pruned = $this->prune();

        return [
            'feeds' => $feeds,
            'inserted' => $inserted,
            'updated' => $updated,
            'pruned' => $pruned,
        ];
    }

    /**
     * 分類並寫入一則新聞（url_hash 去重）。
     *
     * related_symbols 採聯集語義：既有 DB 值 ∪ classifier 判出 ∪ $extraSymbols。
     * 同一 URL 先被 A symbol 寫入、後被 B symbol 寫入時，A 不會被覆蓋——
     * relatedNews(A) 的可見性因此不受後續寫入影響。
     *
     * @param  list<string>  $extraSymbols
     * @return bool true = 新增，false = 更新既有
     */
    public function upsert(NewsItemData $item, array $extraSymbols = []): bool
    {
        $classification = $this->classifier->classify($item->title, $item->summary);

        $hash = sha1($item->url !== ''
            ? $item->url
            : $item->source.'|'.$item->title);

        $existing = NewsItem::query()->where('url_hash', $hash)->first();

        // 聯集：先取既有 related_symbols，避免後續其他 symbol 的寫入覆蓋前一個 symbol 的標記
        $symbols = array_values(array_unique(array_merge(
            $existing?->related_symbols ?? [],
            $classification['symbols'],
            $extraSymbols,
        )));

        NewsItem::updateOrCreate(
            ['url_hash' => $hash],
            [
                'source' => $item->source,
                'title' => $item->title,
                'summary' => $item->summary,
                'url' => $item->url,
                'published_at' => $item->publishedAt !== '' ? $item->publishedAt : null,
                'language' => $item->language,
                'market' => $item->market,
                'topic' => $item->topic,
                'domain' => $classification['domain'],
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
}
