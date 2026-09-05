<?php

namespace App\Console\Commands;

use App\Services\Screener\BacktestService;
use App\Services\Screener\ScreenRuleRegistry;
use Illuminate\Console\Command;

/**
 * 在歷史上回放選股規則，輸出勝率、平均報酬與相對基準的超額。
 *
 * 做成 artisan 命令而非頁面功能：要抓數百根歷史 × 數十檔股票，遠超過一次
 * HTTP 請求該做的事，且這是偶爾執行的分析而非日常操作。
 */
class BacktestScreenerCommand extends Command
{
    protected $signature = 'screener:backtest
        {rules : 必要規則，逗號分隔（例：above_ma20,macd_bullish_cross）}
        {--exclude= : 排除規則，逗號分隔}
        {--symbols= : 限定標的，逗號分隔；預設用內建股池}
        {--limit=20 : 最多回測幾檔（抓歷史很慢，預設保守）}
        {--history=400 : 每檔取用的歷史根數}
        {--horizons=1,5,20 : 往後幾個交易日計算報酬}';

    protected $description = '以歷史資料回放選股規則，計算勝率與相對基準的超額報酬';

    public function handle(BacktestService $service, ScreenRuleRegistry $registry): int
    {
        $rules = $this->keys('rules');
        $excludes = $this->keys('exclude', option: true);
        $known = $registry->keys();

        foreach ([...$rules, ...$excludes] as $key) {
            if (! in_array($key, $known, true)) {
                $this->error("未知規則：{$key}");
                $this->line('可用規則：'.implode(', ', $known));

                return self::FAILURE;
            }
        }

        $horizons = array_map('intval', array_filter(explode(',', (string) $this->option('horizons'))));
        $pool = $this->pool((int) $this->option('limit'));

        if ($pool === []) {
            $this->error('股池為空。');

            return self::FAILURE;
        }

        $this->info(sprintf('回測 %d 檔，規則：%s%s', count($pool), implode('+', $rules),
            $excludes === [] ? '' : '，排除：'.implode('+', $excludes)));
        $this->line('抓取歷史中，可能需要數分鐘…');

        $result = $service->run($pool, $rules, $excludes, (int) $this->option('history'), $horizons);

        if ($result['unsupported_rules'] !== []) {
            $this->newLine();
            $this->warn('以下規則不支援歷史回放，命中數會是 0：'.implode('、', $result['unsupported_rules']));
            $this->warn('籌碼與基本面規則若用當下資料評估過去，屬前視偏誤，結果不可信，故一律不命中。');
        }

        $this->newLine();
        $this->info(sprintf('可回測 %d 檔，產生 %d 個訊號。', $result['scanned'], $result['signals']));

        if ($result['failures'] !== []) {
            $this->line(sprintf('  %d 檔略過（歷史不足或抓取失敗）', count($result['failures'])));
        }

        if ($result['signals'] === 0) {
            $this->newLine();
            $this->warn('沒有訊號，無法統計。');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['天期', '樣本', '勝率', '平均報酬', '中位數', '標準差', '基準勝率', '基準平均', '超額', 't'],
            array_map(static fn (int $horizon, array $row): array => [
                $horizon.' 日',
                $row['samples'],
                $row['win_rate'] === null ? '—' : $row['win_rate'].'%',
                $row['mean'] === null ? '—' : sprintf('%+.2f%%', $row['mean']),
                $row['median'] === null ? '—' : sprintf('%+.2f%%', $row['median']),
                $row['std'] === null ? '—' : sprintf('%.2f%%', $row['std']),
                $row['baseline_win_rate'] === null ? '—' : $row['baseline_win_rate'].'%',
                $row['baseline_mean'] === null ? '—' : sprintf('%+.2f%%', $row['baseline_mean']),
                $row['edge'] === null ? '—' : sprintf('%+.2f%%', $row['edge']),
                $row['t'] === null ? '—' : sprintf('%+.1f', $row['t']),
            ], array_keys($result['stats']), $result['stats']),
        );

        $this->newLine();
        $this->line('「超額」是訊號組平均報酬減去基準組——基準代表隨機挑一天買進。');
        $this->line('規則賺 2% 而同期基準賺 3% 是輸，只看絕對報酬會把多頭行情誤判成規則有效。');
        $this->line('「t」是超額除以標準誤的粗估：|t| < 2 就不要當成證據；連續訊號的報酬重疊，實際還要再打折。');
        $this->newLine();
        $this->warn('未計交易成本、滑價與除權息調整；樣本取自目前股池，含存活者偏誤。');
        $this->warn('結果供相對比較，不是預期報酬，也不構成投資建議。');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function keys(string $name, bool $option = false): array
    {
        $raw = (string) ($option ? $this->option($name) : $this->argument($name));

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** @return array<string, string> */
    private function pool(int $limit): array
    {
        $explicit = $this->keys('symbols', option: true);

        if ($explicit !== []) {
            return array_combine($explicit, $explicit);
        }

        $pool = [];

        foreach ((array) config('screener.universe', []) as $entry) {
            $pool[strtoupper((string) $entry['symbol'])] = (string) ($entry['name'] ?? $entry['symbol']);

            if (count($pool) >= $limit) {
                break;
            }
        }

        return $pool;
    }
}
