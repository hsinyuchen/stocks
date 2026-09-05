<?php

namespace App\Services\Screener;

use App\Contracts\MarketDataProvider;
use App\Models\Instrument;
use App\Services\Chip\ChipDataService;
use App\Services\Margin\MarginDataService;
use App\Services\Screener\Rules\MarginRule;
use App\Services\TechnicalIndicatorService;
use App\Support\CompletedBars;

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
 *   1. 只支援純技術規則。籌碼、基本面、訂單庫存規則的 matchesAt() 一律回 false，
 *      因為用當下資料去評估過去是前視偏誤（這幾類資料的歷史也才剛開始累積）。
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
                $prices = CompletedBars::only($this->marketData->dailyPrices($symbol, $historyDays));
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
            $context = $this->backtestContext(
                [...array_values($rules), ...array_values($excludes)],
                (string) $symbol,
                $historyDays,
            );

            // 最後 maxHorizon 根無法計算完整的前瞻報酬，必須排除——否則樣本會
            // 混入「還沒走完」的訊號，讓最近的結果系統性偏向未實現的方向。
            $lastEvaluable = $count - 1 - $maxHorizon;

            for ($i = self::WARMUP_BARS; $i <= $lastEvaluable; $i++) {
                // 基準：每個可評估的日期都納入，代表「隨機挑一天買進」的結果。
                // 沒有基準就無法分辨「規則有效」與「這段期間本來就在漲」。
                $baseline[] = $this->forwardReturns($closes, $i, $horizons);

                if (! $this->matchesAt($rules, $excludes, $series, $i, $context)) {
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
    private function matchesAt(array $rules, array $excludes, array $series, int $n, array $context = []): bool
    {
        foreach ($rules as $rule) {
            if (! $rule->matchesAt($series, $n, $context)) {
                return false;
            }
        }

        foreach ($excludes as $exclude) {
            if ($exclude->matchesAt($series, $n, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 回放所需的外部資料，整檔股票只載入一次。
     *
     * 融資與籌碼在迴圈外抓、由規則自行截到時點；放進迴圈會對每一根 K 棒重打
     * 一次查詢，400 根就是 400 次。
     *
     * @param  list<ScreenRule>  $rules
     * @return array<string, mixed>
     */
    private function backtestContext(array $rules, string $symbol, int $historyDays): array
    {
        $needs = [];

        // 只有 MarginRule 能把資料截到該根 K 棒的日期再評估，回放才不會前視偏誤
        // （見 unsupportedRules() docblock）。訂單庫存規則（NEEDS_ORDER_INVENTORY）
        // 與籌碼、基本面規則同樣不是 MarginRule，因此需求永遠不會被收集到這裡，
        // 其 matchesAt() 之後一律收到空 context、回 false——這正是「回測給 null」
        // 的實作方式，不需要在下面的 match 額外開一個永遠不會被打到的分支。
        foreach ($rules as $rule) {
            if (! $rule instanceof MarginRule) {
                continue;
            }

            foreach ($rule->requires() as $need) {
                $needs[$need] = true;
            }
        }

        if ($needs === []) {
            return [];
        }

        $instrument = Instrument::query()->where('symbol', $symbol)->first();

        if ($instrument === null) {
            return [];
        }

        // 服務層的 days 是「日曆日」，回測的 historyDays 是「交易根數」。不換算的話
        // 拿到的歷史會比回測區間短一大截，訊號被無聲截斷——實測 260 根的回測只吃到
        // 最近 60 天的融資，樣本從 261 個縮成 38 個，而且全擠在最後兩個月。
        $calendarDays = (int) ceil($historyDays * 1.5);

        $context = [];

        foreach (array_keys($needs) as $need) {
            try {
                $context[$need] = match ($need) {
                    ScreenRule::NEEDS_MARGIN => app(MarginDataService::class)->forInstrument($instrument, $calendarDays),
                    ScreenRule::NEEDS_CHIP => app(ChipDataService::class)->forInstrument($instrument, $calendarDays),
                    default => null,
                };
            } catch (\Throwable) {
                $context[$need] = null;
            }
        }

        return $context;
    }

    /**
     * 不支援回放的規則。
     *
     * 這些規則的 matchesAt() 永遠回 false，若混進必要條件會讓命中數直接歸零。
     * 必須明確回報，否則使用者會誤以為「這組規則歷史上從沒訊號」。
     *
     * 判準不是「有沒有 requires()」而是規則自己有沒有實作時點截斷：融資規則
     * （MarginRule）會把資料截到該根 K 棒的日期再評估，可以正確回放；籌碼、
     * 基本面、訂單庫存規則都沒有接時點資訊，用當下資料評估過去屬前視偏誤，
     * 仍不支援。
     *
     * @param  list<ScreenRule>  $rules
     * @return list<string>
     */
    private function unsupportedRules(array $rules): array
    {
        $out = [];

        foreach ($rules as $rule) {
            if ($rule->requires() !== [] && ! $rule instanceof MarginRule) {
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
