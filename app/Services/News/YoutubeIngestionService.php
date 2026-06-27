<?php

namespace App\Services\News;

use App\Contracts\YoutubeWorkerRunner;
use App\Models\NewsItem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates one YouTube captions ingestion run, mirroring the 2A
 * NewsIngestionService pattern: invoke the worker runner for the configured
 * channels, then classify (domain + symbols via NewsClassifier), dedup by
 * url_hash, and upsert each normalized video item into news_items with
 * kind='video'.
 *
 * Each item is wrapped in its own try/catch so one malformed item never aborts
 * the batch (the 2A per-source isolation lesson). The runner itself is tolerant
 * of worker/network failure (returns []), so this service never touches Python
 * or YouTube directly and is fully testable with a fake runner.
 */
class YoutubeIngestionService
{
    public function __construct(
        private readonly YoutubeWorkerRunner $runner,
        private readonly NewsClassifier $classifier,
    ) {}

    /**
     * Run a full YouTube ingestion pass.
     *
     * @return array{inserted: int, updated: int, items: int}
     */
    public function ingest(): array
    {
        $items = $this->runner->run(
            (array) config('youtube.channels', []),
            [
                'per_channel_limit' => (int) config('youtube.per_channel_limit', 5),
                'languages' => (array) config('youtube.languages', []),
                'excerpt_chars' => (int) config('youtube.excerpt_chars', 1500),
            ],
        );

        $inserted = 0;
        $updated = 0;

        foreach ($items as $item) {
            try {
                if ($this->store($item)) {
                    $inserted++;
                } else {
                    $updated++;
                }
            } catch (Throwable $e) {
                Log::warning('youtube ingest: item failed', [
                    'error' => $e->getMessage(),
                    'url' => $item['url'] ?? null,
                ]);
            }
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'items' => count($items),
        ];
    }

    /**
     * Classify, dedup and upsert one normalized video item.
     *
     * @param  array<string, mixed>  $item
     * @return bool true when a new row was inserted, false when an existing row
     *              was updated (deduplicated by url_hash)
     */
    private function store(array $item): bool
    {
        $source = (string) ($item['source'] ?? '');
        $title = (string) ($item['title'] ?? '');
        $summary = (string) ($item['summary'] ?? '');
        $url = (string) ($item['url'] ?? '');

        if ($title === '' && $source === '') {
            throw new \InvalidArgumentException('youtube item missing source and title');
        }

        $classification = $this->classifier->classify($title, $summary);

        $hash = sha1($url !== '' ? $url : $source.'|'.$title);

        $publishedAt = (string) ($item['published_at'] ?? '');

        $existing = NewsItem::where('url_hash', $hash)->exists();

        NewsItem::updateOrCreate(
            ['url_hash' => $hash],
            [
                'source' => $source,
                'title' => $title,
                'summary' => $summary,
                'url' => $url,
                'published_at' => $publishedAt !== '' ? $publishedAt : null,
                'language' => (string) ($item['language'] ?? 'zh-TW'),
                'market' => $item['market'] ?? null,
                'topic' => (string) ($item['topic'] ?? 'macro'),
                'kind' => 'video',
                'domain' => $classification['domain'],
                'related_symbols' => $classification['symbols'],
            ],
        );

        return ! $existing;
    }
}
