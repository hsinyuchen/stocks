<?php

namespace App\Console\Commands;

use App\Services\News\NewsIngestionService;
use Illuminate\Console\Command;

/**
 * Thin wrapper that runs a full news ingestion pass and reports per-feed
 * counts. Registered on the configured daily schedule in routes/console.php.
 */
class IngestNewsCommand extends Command
{
    protected $signature = 'news:ingest';

    protected $description = 'Fetch the configured RSS feeds, classify and store news items';

    public function handle(NewsIngestionService $service): int
    {
        $result = $service->ingest();

        $this->info(sprintf(
            'News ingest complete: %d inserted, %d updated, %d pruned.',
            $result['inserted'],
            $result['updated'],
            $result['pruned'],
        ));

        foreach ($result['feeds'] as $feed => $count) {
            $this->line(sprintf('  %s: %d', $feed, $count));
        }

        return self::SUCCESS;
    }
}
