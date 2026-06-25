<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for YouTube captions ingestion. When disabled, the
    | youtube:ingest command is a no-op.
    |
    */

    'enabled' => (bool) env('YOUTUBE_INGEST_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Python Binary + Worker Script
    |--------------------------------------------------------------------------
    |
    | The captions worker runs under the project venv python by default
    | (youtube-transcript-api is installed there). Override YOUTUBE_PYTHON for
    | other setups (e.g. a POSIX venv at scripts/.venv/bin/python).
    |
    */

    'python' => env('YOUTUBE_PYTHON', base_path('scripts/.venv/Scripts/python.exe')),
    'worker' => base_path('scripts/youtube_worker.py'),

    /*
    |--------------------------------------------------------------------------
    | Per-Channel Limit + Excerpt Length
    |--------------------------------------------------------------------------
    |
    | How many recent videos to take per channel, and how many transcript
    | characters to keep as the item summary (captions-only, excerpt-only).
    |
    */

    'per_channel_limit' => (int) env('YOUTUBE_PER_CHANNEL_LIMIT', 5),
    'excerpt_chars' => (int) env('YOUTUBE_EXCERPT_CHARS', 1500),

    /*
    |--------------------------------------------------------------------------
    | Caption Languages
    |--------------------------------------------------------------------------
    |
    | Transcript language priority. The first available track wins.
    |
    */

    'languages' => ['zh-TW', 'zh-Hant', 'zh', 'en'],

    /*
    |--------------------------------------------------------------------------
    | Ingest Schedule
    |--------------------------------------------------------------------------
    |
    | Wall-clock times (in the given timezone) at which youtube:ingest runs.
    |
    */

    'schedule' => [
        'times' => ['09:30'],
        'timezone' => 'Asia/Taipei',
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | Curated finance channels discovered via YouTube channel RSS
    | (feeds/videos.xml?channel_id=...). Each entry: channel_id, name,
    | market (TW|US|INTL), language. Expandable later.
    |
    */

    'channels' => [
        ['channel_id' => 'UCvJJ_dzjViJCoLf5uKUTwoA', 'name' => 'CNBC', 'market' => 'US', 'language' => 'en'],
        ['channel_id' => 'UCIALMKvObZNtJ6AmdCLP7Lg', 'name' => 'Bloomberg Television', 'market' => 'US', 'language' => 'en'],
        ['channel_id' => 'UCEAZeUIeJs0IjQiqTCdVSIg', 'name' => 'Yahoo Finance', 'market' => 'US', 'language' => 'en'],
    ],

];
