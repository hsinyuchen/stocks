<?php

namespace App\Services\Screener;

use App\Contracts\MarketDataProvider;
use App\Services\TechnicalIndicatorService;

/**
 * 選股規則的歷史回放。
 *
 * 這是整個專案唯一能把「我認為這條規則有用」變成「這條規則在歷史上的表現是
 * 這樣」的機制。技術面、籌碼面、基本面、傳導鏈與強度分數，先前都沒有任何一項
 * 經過驗證。
 *
 * 作法：對每檔股票取一段夠長的歷史，整段只算一次指標序列，再逐日評估規則；
 * 命中就記錄其後 N 日的報酬。規則契約的 evaluate() 本來就吃索引，因此逐點
 * 評估不需要為每個日期重算指標——那會是 O(n²)。
 *
 * 三個必須講清楚的限制：
 *   1. 只支援純技術規則。籌碼與基本面的 matchesAt() 一律回 false，因為用當下
 *      資料去評估過去是前視偏誤（fundamentals 的歷史也才剛開始累積）。
 *   2. 不計交易成本、滑價、流動性與除權息調整。
 *   3. 樣本來自目前的股池，本身就有存活者偏誤——下市或早已消失的標的不在其中。
 *
 * 因此結果是「相對比較」的依據，不是預期報酬。
 */
class BacktestService
{
    /** 指標暖身所需的最少前置根數，與 BaseRule::MIN_BARS 對齊。 */
    private const WARMUP_BARS = 30;

    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly TechnicalIndicatorService $indicators,
        private readonly ScreenRuleRegistry $registry,
    ) {}

    /**
     * @param  array<string, string>  $pool  symbol => name
     * @param  list<string>  $ruleKeys
     * @param  list<string>  $excludeKeys
     * @param  list<int>  $horizons  往後幾個交易日計算報酬
     * @return array<string, mixed>
     */
    public function run(
        array $pool,
        array $ruleKeys,
        array $excludeKeys = [],
        int $historyDays = 400,
        array $horizons = [1, 5, 20],
    ): array {
        $all = $this->registry->all();
        $rules = array_intersect_key($all, array_flip($ruleKeys));
        $excludes = array_intersect_key($all, array_flip($excludeKeys));

        $unsupported = $this->unsupportedRules([...array_values($rules), ...array_values($excludes)]);
        $maxHorizon = max($horizons);

        $signals = [];
        $baseline = [];
        $scanned = 0;
        $failures = [];

        foreach ($pool as $symbol => $name) {
            try {
                $prices = $this->marketData->dailyPrices($symbol, $historyDays);
            } catch (\Throwable $exception) {
                $failures[] = ['symbol' => $symbol, 'reason' => $exception->getMessage()];

                continue;
            }

            $count = count($prices);

            if ($count < self::WARMUP_BARS + $maxHorizon + 1) {
                $failures[] = ['symbol' => $symbol, 'reason' => "歷史不足（{$count} 根）"];

                continue;
            }

            $scanned++;
            $series = $this->indicators->series($prices);
            $closes = $series['close'];

            // 最後 maxHorizon 根無法計算完整的前瞻報酬，必須排除——否則樣本會
            // 混入「還沒走完」的訊號，讓最近的結果系統性偏向未實現的方向。
            $lastEvaluable = $count - 1 - $maxHorizon;

            for ($i = self::WARMUP_BARS; $i <= $lastEvaluable; $i++) {
                // 基準：每個可評估的日期都納入，代表「隨機挑一天買進」的結果。
                // 沒有基準就無法分辨「規則有效」與「這段期間本來就在漲」。
                $baseline[] = $this->forwardReturns($closes, $i, $horizons);

                if (! $this->matchesAt($rules, $excludes, $series, $i)) {
                    continue;
                }

                $signals[] = [
                    'symbol' => $symbol,
                    'date' => $series['dates'][$i] ?? '',
                    'close' => $closes[$i],
                    'returns' => $this->forwardReturns($closes, $i, $horizons),
                ];
            }
        }

        return [
            'signals' => count($signals),
            'scanned' => $scanned,
            'failures' => $failures,
            'unsupported_rules' => $unsupported,
            'horizons' => $horizons,
            'stats' => $this->summarize($signals, $baseline, $horizons),
            'sample' => array_slice($signals, -10),
        ];
    }

    /**
     * @param  array<string, ScreenRule>  $rules
     * @param  array<string, ScreenRule>  $excludes
     * @param  array<string, list<int|float|null>>  $series
     */
    private function matchesAt(array $rules, array $excludes, array $series, int $n): bool
    {
        foreach ($rules as $rule) {
            if (! $rule->matchesAt($series, $n)) {
                return false;
            }
        }

        foreach ($excludes as $exclude) {
            if ($exclude->matchesAt($series, $n)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 不支援回放的規則。
     *
     * 這些規則的 matchesAt() 永遠回 false，若混進必要條件會讓命中數直接歸零。
     * 必須明確回報，否則使用者會誤以為「這組規則歷史上從沒訊號」。
     *
     * @param  list<ScreenRule>  $rules
     * @return list<string>
     */
    private function unsupportedRules(array $rules): array
    {
        $out = [];

        foreach ($rules as $rule) {
            if ($rule->requires() !== []) {
                $out[] = $rule->key();
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<float>  $closes
     * @param  list<int>  $horizons
     * @return array<int, float|null>
     */
    private function forwardReturns(array $closes, int $n, array $horizons): array
    {
        $entry = (float) $closes[$n];
        $out = [];

        foreach ($horizons as $horizon) {
            $exitIndex = $n + $horizon;

            $out[$horizon] = ($entry > 0 && isset($closes[$exitIndex]))
                ? round(((float) $closes[$exitIndex] / $entry - 1) * 100, 4)
                : null;
        }

        return $out;
    }

    /**
     * 訊號組與基準組的比較。
     *
     * edge 是兩者平均報酬的差：規則賺 2%% 但同期基準賺 3%% 是輸，只看絕對報酬
     * 會把多頭行情誤判成規則有效。
     *
     * @param  list<array<string, mixed>>  $signals
     * @param  list<array<int, float|null>>  $baseline
     * @param  list<int>  $horizons
     * @return array<int, array<string, float|int|null>>
     */
    private function summarize(array $signals, array $baseline, array $horizons): array
    {
        $stats = [];

        foreach ($horizons as $horizon) {
            $signalReturns = $this->column(array_column($signals, 'returns'), $horizon);
            $baselineReturns = $this->column($baseline, $horizon);

            $signalMean = $this->mean($signalReturns);
            $baselineMean = $this->mean($baselineReturns);

            $stats[$horizon] = [
                'samples' => count($signalReturns),
                'win_rate' => $this->winRate($signalReturns),
                'mean' => $signalMean,
                'median' => $this->median($signalReturns),
                'baseline_mean' => $baselineMean,
                'baseline_win_rate' => $this->winRate($baselineReturns),
                'edge' => ($signalMean !== null && $baselineMean !== null)
                    ? round($signalMean - $baselineMean, 4)
                    : null,
            ];
        }

        return $stats;
    }

    /**
     * @param  list<array<int, float|null>>  $rows
     * @return list<float>
     */
    private function column(array $rows, int $horizon): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (isset($row[$horizon])) {
                $out[] = (float) $row[$horizon];
            }
        }

        return $out;
    }

    /** @param list<float> $values */
    private function mean(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 4);
    }

    /** @param list<float> $values */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? round(($values[$middle - 1] + $values[$middle]) / 2, 4)
            : round($values[$middle], 4);
    }

    /** @param list<float> $values */
    private function winRate(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $wins = count(array_filter($values, static fn (float $v): bool => $v > 0));

        return round($wins / count($values) * 100, 2);
    }
}
