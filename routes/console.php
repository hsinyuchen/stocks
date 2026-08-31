<?php

use App\Services\FinancialStatements\StaleFetchReaper;
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
 * 價格預載。**全站唯一會主動刷新 daily_prices 的排程。**
 *
 * 少了它，價格只在有人呼叫 dailyPrices() 時才抓：實測 67 檔有價格的標的裡有 31 檔
 * 停在 15–30 天前，早就過了 CachedMarketDataProvider::isFresh() 的 TTL，只是自從
 * 被批次拉進來後沒有任何人再碰過。技術面的新鮮度 gate 會把那些標的的技術立場全部
 * 判成不可評估——gate 是止血，這條排程才是根治。
 *
 * 指令本來就存在（screener:warm），只是從來沒被排進來，形狀與症狀跟下面那個
 * queue:work 曾經的處境一樣。時間讀 config，與 news／youtube 同一形狀。
 *
 * 前提同上：部署環境要有每分鐘的 schedule:run，否則這裡寫什麼都不會執行。
 */
foreach ((array) config('screener.schedule.times') as $time) {
    Schedule::command('screener:warm')
        ->dailyAt($time)
        ->timezone(config('screener.schedule.timezone'))
        // 一次要打近百檔上游，跑超過一天的間隔時不該再疊一份上去。
        ->withoutOverlapping()
        ->runInBackground();
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

/*
 * 財報擷取的死亡收割。
 *
 * 沒有人瀏覽的標的會永遠停在 running 或 queued——reader 是被動的，只有有人打開
 * 頁面才會看到狀態。頻率用每五分鐘而不是每分鐘：判定門檻本來就是 240 秒，
 * 掃得再密也只是多打幾次同樣的 UPDATE。
 */
// withoutOverlapping() 的互斥鍵是 name() 給的字串，框架不查重：日後若複製這段
// 加第二條 Schedule::call，務必換一個 name 字串，否則兩者會共用同一把鎖互相排斥。
Schedule::call(fn () => app(StaleFetchReaper::class)->reap())
    ->everyFiveMinutes()
    ->name('financial-statements:reap')
    ->withoutOverlapping();
