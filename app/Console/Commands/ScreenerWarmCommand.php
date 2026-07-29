<?php

namespace App\Console\Commands;

use App\Contracts\MarketDataProvider;
use App\Services\Screener\ScreenerService;
use Illuminate\Console\Command;

class ScreenerWarmCommand extends Command
{
    protected $signature = 'screener:warm';

    protected $description = '預載選股器股池的價格資料（建立/更新 daily_prices 快取），可重複執行。';

    public function handle(MarketDataProvider $marketData, ScreenerService $screener): int
    {
        // 與掃描共用同一份清單（標的清單，排除指數）。讀 config 的話會回到
        // 「預載了 A、實際掃描的是 B」——那正是股池來源統一要解決的問題。
        $universe = $screener->baseSymbols();
        $days = (int) config('screener.history_days', 250);
        $ok = 0;
        $failed = [];

        if ($universe === []) {
            $this->warn('標的清單是空的。請先於 /admin/instruments 新增，或執行 php artisan instruments:seed-universe。');

            return self::SUCCESS;
        }

        foreach (array_keys($universe) as $symbol) {
            try {
                $marketData->dailyPrices($symbol, $days);
                $ok++;
                $this->line("  {$symbol} ok");
            } catch (\Throwable $exception) {
                $failed[] = $symbol;
                $this->warn("  {$symbol} failed: {$exception->getMessage()}");
            }
        }

        $this->info("預載完成：{$ok} / ".count($universe).' 支成功。');

        if ($failed !== []) {
            $this->warn('失敗（重跑本命令即可重試）：'.implode(', ', $failed));
        }

        return self::SUCCESS;
    }
}
