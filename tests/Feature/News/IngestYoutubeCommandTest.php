<?php

namespace Tests\Feature\News;

use App\Contracts\YoutubeWorkerRunner;
use App\Models\NewsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestYoutubeCommandTest extends TestCase
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

    /** @return list<array<string, mixed>> */
    private function cannedVideo(): array
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
        ];
    }

    public function test_command_runs_ingestion_and_persists_video_items(): void
    {
        config(['youtube.enabled' => true]);

        $this->app->instance(YoutubeWorkerRunner::class, $this->fakeRunner($this->cannedVideo()));

        $this->artisan('youtube:ingest')->assertSuccessful();

        $this->assertSame(1, NewsItem::where('kind', 'video')->count());

        $item = NewsItem::where('url', 'https://www.youtube.com/watch?v=tech1')->firstOrFail();
        $this->assertSame('video', $item->kind);
        $this->assertSame('CNBC', $item->source);
        $this->assertSame('tech', $item->domain);
        $this->assertContains('2330.TW', $item->related_symbols);
        $this->assertContains('NVDA', $item->related_symbols);
    }

    public function test_command_is_a_noop_when_ingest_disabled(): void
    {
        config(['youtube.enabled' => false]);

        // A runner that would explode if it were ever invoked.
        $this->app->instance(YoutubeWorkerRunner::class, new class implements YoutubeWorkerRunner
        {
            public function run(array $channels, array $options): array
            {
                throw new \RuntimeException('runner should not be called when disabled');
            }
        });

        $this->artisan('youtube:ingest')->assertSuccessful();

        $this->assertSame(0, NewsItem::count());
    }
}
