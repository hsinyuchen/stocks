<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 接線契約：決策型消費端一定要先過 CompletedBars::only()。
 *
 * 行為本身很難逐一整合測試（要湊出盤中棒剛好讓 KD 交叉、再看警報是否被消耗），
 * 但「有沒有接」是可以釘住的——漏接的代價是警報被半根棒引爆、分析把盤中判讀存進 DB。
 * 形式沿用本專案的 JSX 結構契約測試。
 */
class CompletedBarsWiringTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function sites(): array
    {
        return [
            'alerts' => ['app/Services/Alerts/AlertEvaluator.php', 'self::HISTORY_DAYS'],
            'symbol context' => ['app/Services/Analysis/SymbolContextService.php', 'self::PRICE_BARS'],
            'watchlist' => ['app/Services/Analysis/WatchlistAnalysisService.php', 'self::PRICE_BARS'],
            'market weight' => ['app/Services/Analysis/MarketWeightAnalysisService.php', 'self::PRICE_BARS'],
            'screener' => ['app/Services/Screener/ScreenerService.php', '$historyDays'],
            'backtest' => ['app/Services/Screener/BacktestService.php', '$historyDays'],
            'health fresh' => ['app/Services/Health/HealthSnapshotBuilder.php', '$bars'],
        ];
    }

    #[DataProvider('sites')]
    public function test_consumer_strips_partial_bars(string $path, string $daysExpression): void
    {
        $source = file_get_contents(__DIR__.'/../../'.$path);

        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            'CompletedBars::only($this->marketData->dailyPrices(',
            $source,
            "{$path} 必須先過 CompletedBars::only() 才能用序列做決定。",
        );
        $this->assertStringContainsString($daysExpression, $source);
    }
}
