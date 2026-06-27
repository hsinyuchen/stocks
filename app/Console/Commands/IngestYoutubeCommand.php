<?php

namespace App\Console\Commands;

use App\Services\News\YoutubeIngestionService;
use Illuminate\Console\Command;

/**
 * Thin wrapper that runs a full YouTube captions ingestion pass and reports
 * counts. Registered on the configured daily schedule in routes/console.php.
 *
 * When youtube.enabled is false the command is a no-op (prints a notice and
 * succeeds) so the schedule can be left in place but globally switched off.
 */
class IngestYoutubeCommand extends Command
{
    protected $signature = 'youtube:ingest';

    protected $description = 'Fetch recent finance YouTube videos, classify and store them as news items';

    public function handle(): int
    {
        if (! config('youtube.enabled')) {
            $this->info('YouTube ingest is disabled (youtube.enabled=false); nothing to do.');

            return self::SUCCESS;
        }

        $service = app(YoutubeIngestionService::class);

        $result = $service->ingest();

        $this->info(sprintf(
            'YouTube ingest complete: %d inserted, %d updated, %d items.',
            $result['inserted'],
            $result['updated'],
            $result['items'],
        ));

        return self::SUCCESS;
    }
}
