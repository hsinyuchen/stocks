<?php

namespace App\Console\Commands;

use App\Contracts\MarketDataProvider;
use Illuminate\Console\Command;

class ScreenerWarmCommand extends Command
{
    protected $signature = 'screener:warm';

    protected $description = '預載選股器股池的價格資料（建立/更新 daily_prices 快取），可重複執行。';

    public function handle(MarketDataProvider $marketData): int
    {
        $universe = (array) config('screener.universe', []);
        $days = (int) config('screener.history_days', 250);
        $ok = 0;
        $failed = [];

        foreach ($universe as $entry) {
            $symbol = strtoupper((string) $entry['symbol']);

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
