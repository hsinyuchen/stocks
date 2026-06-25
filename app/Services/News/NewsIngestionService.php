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
                if ($this->store($item)) {
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
     * Classify, dedup and upsert one item.
     *
     * @return bool true when a new row was inserted, false when an existing
     *              row was updated (deduplicated by url_hash)
     */
    private function store(NewsItemData $item): bool
    {
        $classification = $this->classifier->classify($item->title, $item->summary);

        $hash = sha1($item->url !== ''
            ? $item->url
            : $item->source.'|'.$item->title);

        $existing = NewsItem::where('url_hash', $hash)->exists();

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
                'related_symbols' => $classification['symbols'],
            ],
        );

        return ! $existing;
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
