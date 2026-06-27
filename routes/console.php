<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

foreach ((array) config('news.schedule.times') as $time) {
    Schedule::command('news:ingest')
        ->dailyAt($time)
        ->timezone(config('news.schedule.timezone'));
}

foreach ((array) config('youtube.schedule.times') as $time) {
    Schedule::command('youtube:ingest')
        ->dailyAt($time)
        ->timezone(config('youtube.schedule.timezone'));
}
