<?php

namespace App\Contracts;

interface YoutubeWorkerRunner
{
    /**
     * Run the YouTube captions worker for the given channels and return the
     * normalized video item arrays it emits.
     *
     * On worker failure or non-JSON output, returns an empty list so a broken
     * worker never aborts ingestion.
     *
     * @param  list<array<string, mixed>>  $channels
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function run(array $channels, array $options): array;
}
