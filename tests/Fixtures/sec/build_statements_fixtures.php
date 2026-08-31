<?php

use App\Services\Fundamentals\SecTickerCikResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

/**
 * 從 SEC 抓 companyfacts 並裁成測試用的最小集合。
 *
 * 完整檔太大（AAPL 3.8MB、MSFT 4.9MB），只留本層會讀的 tag。裁切後仍是**真實資料**
 * ——期間、accn、filed、val 一個字都不改。「用沒有 frame 的列驗證」的前提就是
 * 資料必須是真的。
 *
 * 用法（需要網路）：php tests/Fixtures/sec/build_statements_fixtures.php
 */

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$targets = ['COST' => 'cost', 'AAPL' => 'aapl', 'RGTI' => 'rgti'];

$keep = [];
foreach (config('financial_statements.sec_tags') as $tags) {
    foreach ($tags as $t) {
        $keep[$t] = true;
    }
}
foreach (config('financial_statements.sec_eps_tags') as $tags) {
    foreach ($tags as $t) {
        $keep[$t] = true;
    }
}
foreach (config('financial_statements.anchor_tags') as $t) {
    $keep[$t] = true;
}

$ua = config('order_inventory.sec.user_agent');
$resolver = app(SecTickerCikResolver::class);

foreach ($targets as $ticker => $slug) {
    $cik = $resolver->resolve($ticker);
    if ($cik === null) {
        fwrite(STDERR, "$ticker: 找不到 CIK\n");

        continue;
    }

    $url = str_replace('{cik}', $cik, config('order_inventory.sec.company_facts_url'));
    $res = Http::withHeaders([
        'User-Agent' => $ua,
        'Accept-Encoding' => 'gzip, deflate',
    ])->timeout(40)->get($url);

    if (! $res->successful()) {
        fwrite(STDERR, "$ticker: HTTP {$res->status()}\n");

        continue;
    }

    $full = $res->json();
    $trimmed = [
        'cik' => $full['cik'] ?? null,
        'entityName' => $full['entityName'] ?? null,
        'facts' => ['us-gaap' => []],
    ];

    foreach (($full['facts']['us-gaap'] ?? []) as $tag => $def) {
        if (isset($keep[$tag])) {
            $trimmed['facts']['us-gaap'][$tag] = $def;
        }
    }

    $path = __DIR__."/{$slug}_statements_companyfacts.json";
    file_put_contents($path, json_encode($trimmed, JSON_UNESCAPED_SLASHES));
    printf("%s → %s (%.2f MB, %d tags)\n", $ticker, basename($path),
        filesize($path) / 1048576, count($trimmed['facts']['us-gaap']));

    usleep(300000);   // SEC 官方限制 10 req/s，這裡遠低於它
}
