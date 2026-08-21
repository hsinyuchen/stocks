<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Services\Futures\MarketFuturesFlipDetector;
use App\Services\Rates\RatesRegimeService;
use App\Services\TechnicalIndicatorService;
use Throwable;

/**
 * 大盤真翻空研判（方法論 5 維計分）。
 *
 * 真正的波段翻空需跨市場多維度同時成立，單一維度常是雜訊或避險。五個維度：
 *  1. 期貨籌碼：外資期貨淨空連續站上門檻（複用 MarketFuturesFlipDetector）。
 *  2. 現貨籌碼：外資現貨淨賣超連續達標。
 *  3. 市場結構：^TWII 同時跌破月線與季線。
 *  4. 外匯資金：USD/TWD 站上均線且區間走升（台幣趨勢貶值）。
 *  5. 利率環境：美債主窗口殖利率上行，或殖利率曲線倒掛。
 *
 * 改為計分而非嚴格 AND，原因是舊版把「條件不成立」與「資料抓不到」都壓成
 * false：任一資料源斷線，警報當天就完全不可能觸發，而使用者看不出是哪一種。
 * 現在缺料只是不計分，並在 unavailable 明列。
 *
 * 利率維度採寬定義（上行「或」倒掛），因為台股崩跌未必伴隨美債殖利率上行——
 * 避險時資金湧入美債，殖利率反而下行；嚴定義會讓這類情境被美債否決掉台股
 * 自身的多維共振訊號。
 */
class MarketBearishFlipDetector
{
    public function __construct(
        private readonly MarketFuturesFlipDetector $futuresFlip,
        private readonly MarketInstitutionalService $institutional,
        private readonly MarketDataProvider $marketData,
        private readonly TechnicalIndicatorService $indicators,
        private readonly RatesRegimeService $rates,
    ) {}

    /**
     * @return array{triggered: bool, score: int, max: int, min_score: int, dimensions: array<string, bool>, unavailable: list<string>, reason: ?string}
     */
    public function detect(): array
    {
        $config = (array) config('alerts.market_bearish_flip', []);
        $minScore = max(1, (int) ($config['min_dimensions'] ?? 4));

        // null 代表資料抓不到，與 false（條件不成立）語意不同。
        $raw = [
            'futures' => $this->futuresDimension(),
            'spot' => $this->spotDimension($config),
            'technical' => $this->technicalDimension($config),
            'fx' => $this->fxDimension($config),
            'rates' => $this->ratesDimension(),
        ];

        $dimensions = [];
        $unavailable = [];
        $score = 0;

        foreach ($raw as $key => $value) {
            if ($value === null) {
                $unavailable[] = $key;
            }

            $met = $value === true;
            $dimensions[$key] = $met;

            if ($met) {
                $score++;
            }
        }

        $max = count($raw);
        $triggered = $score >= $minScore;

        return [
            'triggered' => $triggered,
            'score' => $score,
            'max' => $max,
            'min_score' => $minScore,
            'dimensions' => $dimensions,
            'unavailable' => $unavailable,
            'reason' => $triggered ? null : $this->missingReason($dimensions, $unavailable, $score, $max),
        ];
    }

    /**
     * 期貨維度：複用單維翻空偵測器。
     *
     * latest_date 為 null 代表序列不足，屬缺料而非條件不成立。
     */
    private function futuresDimension(): ?bool
    {
        $result = $this->futuresFlip->detect();

        return $result['latest_date'] === null ? null : $result['triggered'];
    }

    /** 現貨維度：外資現貨淨賣超連續 streak 日 ≤ -門檻（元）。 */
    private function spotDimension(array $config): ?bool
    {
        $streak = max(1, (int) ($config['spot_streak_days'] ?? 3));
        // 億元 → 元。
        $threshold = (int) ($config['spot_net_sell_yi'] ?? 150) * 100_000_000;

        $series = $this->institutional->foreignNetSeries($streak + 3);

        if (count($series) < $streak) {
            return null;
        }

        foreach (array_slice($series, -$streak) as $day) {
            if ($day['net'] > -$threshold) {
                return false;
            }
        }

        return true;
    }

    /** 技術維度：^TWII 最新收盤同時跌破 ma20（月線）與 ma60（季線）。 */
    private function technicalDimension(array $config): ?bool
    {
        $symbol = (string) ($config['index_symbol'] ?? '^TWII');
        $days = max(60, (int) ($config['index_history_days'] ?? 90));

        try {
            $prices = $this->marketData->dailyPrices($symbol, $days);

            if (count($prices) < 60) {
                return null;
            }

            $series = $this->indicators->series($prices);
        } catch (Throwable) {
            return null;
        }

        $close = $this->lastNumeric($series['close'] ?? []);
        $ma20 = $this->lastNumeric($series['ma20'] ?? []);
        $ma60 = $this->lastNumeric($series['ma60'] ?? []);

        if ($close === null || $ma20 === null || $ma60 === null) {
            return null;
        }

        return $close < $ma20 && $close < $ma60;
    }

    /** 匯率維度：USD/TWD 最新收盤 > fx_ma_days 日均，且高於區間起點（台幣趨勢貶值）。 */
    private function fxDimension(array $config): ?bool
    {
        $symbol = (string) ($config['fx_symbol'] ?? 'USDTWD=X');
        $maDays = max(2, (int) ($config['fx_ma_days'] ?? 20));

        try {
            // 匯率序列不走 TechnicalIndicatorService：外匯 OHLC/量常缺欄位會觸發其嚴格校驗。
            $prices = $this->marketData->dailyPrices($symbol, $maDays + 10);
        } catch (Throwable) {
            return null;
        }

        $closes = [];

        foreach ($prices as $price) {
            $value = is_array($price) ? ($price['close'] ?? null) : ($price->close ?? null);

            if (is_numeric($value)) {
                $closes[] = (float) $value;
            }
        }

        if (count($closes) < $maDays) {
            return null;
        }

        $window = array_slice($closes, -$maDays);
        $latest = $window[count($window) - 1];
        $average = array_sum($window) / count($window);

        // 站上均線（上升趨勢）且相對區間起點走升 → USD 走強 = 台幣走貶。
        return $latest > $average && $latest > $window[0];
    }

    /**
     * 利率維度：主窗口殖利率上行，或曲線倒掛。
     *
     * 寬定義涵蓋「資金成本上升」與「衰退預期」兩條利空路徑。
     */
    private function ratesDimension(): ?bool
    {
        $regime = $this->rates->current();

        if (! $regime->available) {
            return null;
        }

        $primary = $regime->primary();

        if ($primary === null) {
            return null;
        }

        return $primary['level'] === 'bear' || $regime->inverted;
    }

    /**
     * @param  array<int, mixed>  $series
     */
    private function lastNumeric(array $series): ?float
    {
        $value = end($series);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, bool>  $dimensions
     * @param  list<string>  $unavailable
     */
    private function missingReason(array $dimensions, array $unavailable, int $score, int $max): string
    {
        $labels = [
            'futures' => '期貨淨空',
            'spot' => '現貨連續大賣',
            'technical' => '破月線且季線',
            'fx' => '台幣趨勢貶值',
            'rates' => '美債利率環境轉弱',
        ];

        $missing = [];

        foreach ($dimensions as $key => $met) {
            if (! $met) {
                $missing[] = $labels[$key] ?? $key;
            }
        }

        $reason = sprintf('%d 維中 %d 維成立，缺：%s', $max, $score, implode('、', $missing));

        if ($unavailable !== []) {
            $names = array_map(static fn (string $key): string => $labels[$key] ?? $key, $unavailable);
            $reason .= sprintf('（其中無資料：%s）', implode('、', $names));
        }

        return $reason;
    }
}
