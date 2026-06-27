<?php

namespace App\Services\News;

use App\Contracts\YoutubeWorkerRunner;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs the Python captions worker via Symfony Process: the channel list plus
 * options are written to the worker's stdin as JSON, and its stdout is decoded
 * back into normalized item arrays.
 *
 * Tolerant by design (the 2A per-source isolation lesson): any worker failure
 * or non-JSON output yields an empty list rather than throwing, so a broken or
 * blocked worker reports zero rows instead of crashing ingestion.
 */
class ProcessYoutubeWorkerRunner implements YoutubeWorkerRunner
{
    public function __construct(
        private readonly string $python,
        private readonly string $worker,
    ) {}

    public function run(array $channels, array $options): array
    {
        $payload = ['channels' => $channels] + $options;

        $process = new Process([$this->python, $this->worker]);
        $process->setInput((string) json_encode($payload));
        $process->setTimeout(null);

        try {
            $process->run();
        } catch (Throwable $e) {
            Log::warning('youtube worker: process failed to start', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $process->isSuccessful()) {
            Log::warning('youtube worker: non-zero exit', [
                'exit_code' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);

            return [];
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded)) {
            Log::warning('youtube worker: non-JSON output');

            return [];
        }

        return array_values($decoded);
    }
}
