<?php

namespace Tests\Feature\News;

use App\Contracts\YoutubeWorkerRunner;
use App\Models\NewsItem;
use App\Services\News\NewsClassifier;
use App\Services\News\YoutubeIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YoutubeIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A fake runner that returns whatever canned items it is given, ignoring the
     * channels/options entirely. No Python, no network, no venv.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function fakeRunner(array $items): YoutubeWorkerRunner
    {
        return new class($items) implements YoutubeWorkerRunner
        {
            /** @param list<array<string, mixed>> $items */
            public function __construct(private array $items) {}

            public function run(array $channels, array $options): array
            {
                return $this->items;
            }
        };
    }

    private function makeService(YoutubeWorkerRunner $runner): YoutubeIngestionService
    {
        return new YoutubeIngestionService($runner, new NewsClassifier);
    }

    /**
     * One finance/tech video (台積電 + Nvidia in the title) and one generic clip.
     *
     * @return list<array<string, mixed>>
     */
    private function cannedItems(): array
    {
        return [
            [
                'source' => 'CNBC',
                'title' => '台積電 and Nvidia drive the AI chip rally',
                'summary' => 'Transcript excerpt about semiconductor demand.',
                'topic' => 'macro',
                'related_symbols' => [],
                'published_at' => '2026-06-20T00:00:00+00:00',
                'url' => 'https://www.youtube.com/watch?v=tech1',
                'language' => 'en',
                'market' => 'US',
                'kind' => 'video',
            ],
            [
                'source' => 'Bloomberg Television',
                'title' => 'A quiet afternoon with nothing in particular',
                'summary' => 'Generic chatter, no tickers here.',
                'topic' => 'macro',
                'related_symbols' => [],
                'published_at' => '2026-06-19T00:00:00+00:00',
                'url' => 'https://www.youtube.com/watch?v=generic1',
                'language' => 'en',
                'market' => 'US',
                'kind' => 'video',
            ],
        ];
    }

    public function test_ingest_stores_classified_video_items(): void
    {
        $result = $this->makeService($this->fakeRunner($this->cannedItems()))->ingest();

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, $result['items']);

        $tech = NewsItem::where('url', 'https://www.youtube.com/watch?v=tech1')->firstOrFail();
        $this->assertSame('video', $tech->kind);
        $this->assertSame('CNBC', $tech->source);
        $this->assertSame('US', $tech->market);
        $this->assertSame('tech', $tech->domain);
        $this->assertContains('2330.TW', $tech->related_symbols);
        $this->assertContains('NVDA', $tech->related_symbols);
        $this->assertNotEmpty($tech->url_hash);

        $generic = NewsItem::where('url', 'https://www.youtube.com/watch?v=generic1')->firstOrFail();
        $this->assertSame('video', $generic->kind);
        $this->assertSame('other', $generic->domain);
        $this->assertSame([], $generic->related_symbols);
    }

    public function test_ingest_twice_deduplicates_by_url_hash(): void
    {
        $first = $this->makeService($this->fakeRunner($this->cannedItems()))->ingest();
        $second = $this->makeService($this->fakeRunner($this->cannedItems()))->ingest();

        $this->assertSame(2, $first['inserted']);
        $this->assertSame(0, $first['updated']);

        $this->assertSame(0, $second['inserted']);
        $this->assertSame(2, $second['updated']);

        $this->assertSame(2, NewsItem::count());
    }

    public function test_malformed_item_without_url_still_stores_via_source_and_title_hash(): void
    {
        $items = [
            [
                'source' => 'Yahoo Finance',
                'title' => 'No URL but still a video',
                'summary' => 'Captions degraded to description.',
                'topic' => 'macro',
                'related_symbols' => [],
                'published_at' => '',
                'language' => 'en',
                'market' => 'US',
                'kind' => 'video',
                // url intentionally missing
            ],
        ];

        $result = $this->makeService($this->fakeRunner($items))->ingest();

        $this->assertSame(1, $result['inserted']);

        $row = NewsItem::where('source', 'Yahoo Finance')->firstOrFail();
        $this->assertSame('video', $row->kind);
        $this->assertSame(sha1('Yahoo Finance|No URL but still a video'), $row->url_hash);
    }

    public function test_one_bad_item_does_not_abort_the_batch(): void
    {
        $items = [
            ['not' => 'a valid item'], // missing title/source -> per-item isolation
            $this->cannedItems()[0],
        ];

        $result = $this->makeService($this->fakeRunner($items))->ingest();

        $this->assertSame(1, NewsItem::where('kind', 'video')->count());
        $this->assertSame(1, NewsItem::where('url', 'https://www.youtube.com/watch?v=tech1')->count());
    }
}
