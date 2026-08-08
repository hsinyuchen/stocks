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

/*
 * 佇列取件。
 *
 * 共享主機沒有常駐 daemon，用每分鐘的 cron 拼出一個近乎常駐的 worker：預設存活
 * 55 秒，下一分鐘接手，空窗只有幾秒。
 *
 * 兩個參數都來自設定（config/analysis.php 的 cron_worker）而不是寫死：主機能容忍
 * 多長的背景程序事前無從得知，量測完（php artisan host:probe:report）改 .env 就好，
 * 不必動程式碼重新部署。
 *
 * 這件事原本完全沒有排程——.env.example 與 queue:doctor 都叫人「每分鐘執行
 * schedule:run」，但排進去的只有新聞與 YouTube 抓取，沒有任何東西會取件。
 */
$workerMaxSeconds = max(5, (int) config('analysis.cron_worker.max_seconds'));
$workerStopWhenEmpty = config('analysis.cron_worker.stop_when_empty') ? ' --stop-when-empty' : '';

Schedule::command("queue:work --max-time={$workerMaxSeconds} --tries=1 --sleep=3{$workerStopWhenEmpty}")
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * 主機探測。預設不排程——它本身也是一個長壽程序，長期開著等於在 worker 之外再多
 * 佔一份資源。用 if 而不是 ->when()：被 when() 過濾掉的任務仍會出現在 schedule:list，
 * 從清單上看不出它到底有沒有在跑。
 *
 * 刻意與 queue:work 走同一條路徑（everyMinute + withoutOverlapping + runInBackground），
 * 換一條路就測不到同樣的失敗模式。
 */
if (config('host_probe.enabled')) {
    Schedule::command('host:probe')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground();
}
