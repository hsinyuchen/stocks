<?php

namespace App\Console\Commands;

use App\Services\News\NewsIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        // 同時列出「抓到幾則」與「其中幾則是新鮮的」。只看前者會漏掉回應正常
        // 但內容凍結的 feed（實測 WSJ Markets 回 200 且滿滿 20 則，最新一則卻是
        // 547 天前，插入後立即被 prune）。
        foreach ($result['health'] as $feed) {
            $this->line(sprintf(
                '  %-22s items=%-4d fresh=%-4d%s',
                $feed['key'],
                $feed['items'],
                $feed['fresh'],
                $feed['error'] !== null ? '  error: '.$feed['error'] : '',
            ));
        }

        $unhealthy = array_filter($result['health'], static fn (array $f): bool => $f['unhealthy']);

        if ($unhealthy !== []) {
            $this->newLine();
            $this->warn(sprintf('%d 個 feed 連續多次沒有新鮮內容，請檢查來源是否已失效：', count($unhealthy)));

            foreach ($unhealthy as $feed) {
                $this->warn(sprintf('  %s（%s）連續 %d 次無新鮮項目', $feed['key'], $feed['name'], $feed['stale_runs']));
            }
        }

        $this->reclaimSpaceIfNeeded((int) $result['pruned']);

        return self::SUCCESS;
    }

    /**
     * 大量 prune 之後把空間還給磁碟。
     *
     * SQLite 的 DELETE 只把頁面標記為可重用，檔案不會縮小；要真的釋放需要
     * VACUUM。但 VACUUM 會重寫整個檔案並鎖住資料庫，日常那種刪個幾十筆的
     * prune 不值得為此付出代價，故設門檻。
     *
     * 只對 sqlite 執行：MySQL/Postgres 的空間回收機制不同，不該套用同一招。
     */
    private function reclaimSpaceIfNeeded(int $pruned): void
    {
        $threshold = (int) config('news.vacuum_after_pruned', 500);

        if ($threshold <= 0 || $pruned < $threshold) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $before = $this->databaseBytes();
        DB::statement('VACUUM');
        $after = $this->databaseBytes();

        $this->info(sprintf(
            '已回收空間：刪除 %d 筆後執行 VACUUM，%.1f MB → %.1f MB。',
            $pruned,
            $before / 1048576,
            $after / 1048576,
        ));
    }

    private function databaseBytes(): int
    {
        $path = (string) DB::connection()->getConfig('database');

        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }
}
