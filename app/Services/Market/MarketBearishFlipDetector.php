<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Services\Futures\MarketFuturesFlipDetector;
use App\Services\TechnicalIndicatorService;
use Throwable;

/**
 * 大盤真翻空研判（方法論 4 維共振）。
 *
 * 真正的波段翻空需跨市場多維度同時成立，單一維度常是雜訊或避險。本偵測器把四個
 * 維度以 AND 組合：
 *  1. 期貨籌碼：外資期貨淨空連續站上門檻（複用 MarketFuturesFlipDetector）。
 *  2. 現貨籌碼：外資現貨淨賣超連續達標。
 *  3. 市場結構：^TWII 同時跌破月線與季線。
 *  4. 外匯資金：USD/TWD 站上均線且區間走升（台幣趨勢貶值）。
 *
 * 每個維度都 best-effort：缺資料一律視為「不成立」而非「通過」，避免抓不到資料時
 * 反而放行。四維全 true 才 triggered。
 */
class MarketBearishFlipDetector
{
    public function __construct(
        private readonly MarketFuturesFlipDetector $futuresFlip,
        private readonly MarketInstitutionalService $institutional,
        private readonly MarketDataProvider $marketData,
        private readonly TechnicalIndicatorService $indicators,
    ) {}

    /**
     * @return array{triggered: bool, reason: ?string, dimensions: array{futures: bool, spot: bool, technical: bool, fx: bool}}
     */
    public function detect(): array
    {
        $config = (array) config('alerts.market_bearish_flip', []);

        $dimensions = [
            'futures' => $this->futuresDimension(),
            'spot' => $this->spotDimension($config),
            'technical' => $this->technicalDimension($config),
            'fx' => $this->fxDimension($config),
        ];

        $triggered = ! in_array(false, $dimensions, true);

        return [
            'triggered' => $triggered,
            'reason' => $triggered ? null : $this->missingReason($dimensions),
            'dimensions' => $dimensions,
        ];
    }

    /** 期貨維度：複用單維翻空偵測器。 */
    private function futuresDimension(): bool
    {
        return $this->futuresFlip->detect()['triggered'];
    }

    /** 現貨維度：外資現貨淨賣超連續 streak 日 ≤ -門檻（元）。 */
    private function spotDimension(array $config): bool
    {
        $streak = max(1, (int) ($config['spot_streak_days'] ?? 3));
        // 億元 → 元。
        $threshold = (int) ($config['spot_net_sell_yi'] ?? 150) * 100_000_000;

        $series = $this->institutional->foreignNetSeries($streak + 3);

        if (count($series) < $streak) {
            return false;
        }

        foreach (array_slice($series, -$streak) as $day) {
            if ($day['net'] > -$threshold) {
                return false;
            }
        }

        return true;
    }

    /** 技術維度：^TWII 最新收盤同時跌破 ma20（月線）與 ma60（季線）。 */
    private function technicalDimension(array $config): bool
    {
        $symbol = (string) ($config['index_symbol'] ?? '^TWII');
        $days = max(60, (int) ($config['index_history_days'] ?? 90));

        try {
            $prices = $this->marketData->dailyPrices($symbol, $days);

            if (count($prices) < 60) {
                return false;
            }

            $series = $this->indicators->series($prices);
        } catch (Throwable) {
            return false;
        }

        $close = $this->lastNumeric($series['close'] ?? []);
        $ma20 = $this->lastNumeric($series['ma20'] ?? []);
        $ma60 = $this->lastNumeric($series['ma60'] ?? []);

        if ($close === null || $ma20 === null || $ma60 === null) {
            return false;
        }

        return $close < $ma20 && $close < $ma60;
    }

    /** 匯率維度：USD/TWD 最新收盤 > fx_ma_days 日均，且高於區間起點（台幣趨勢貶值）。 */
    private function fxDimension(array $config): bool
    {
        $symbol = (string) ($config['fx_symbol'] ?? 'USDTWD=X');
        $maDays = max(2, (int) ($config['fx_ma_days'] ?? 20));

        try {
            // 匯率序列不走 TechnicalIndicatorService：外匯 OHLC/量常缺欄位會觸發其嚴格校驗。
            $prices = $this->marketData->dailyPrices($symbol, $maDays + 10);
        } catch (Throwable) {
            return false;
        }

        $closes = [];

        foreach ($prices as $price) {
            $value = is_array($price) ? ($price['close'] ?? null) : ($price->close ?? null);

            if (is_numeric($value)) {
                $closes[] = (float) $value;
            }
        }

        if (count($closes) < $maDays) {
            return false;
        }

        $window = array_slice($closes, -$maDays);
        $latest = $window[count($window) - 1];
        $average = array_sum($window) / count($window);

        // 站上均線（上升趨勢）且相對區間起點走升 → USD 走強 = 台幣走貶。
        return $latest > $average && $latest > $window[0];
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
     * @param  array{futures: bool, spot: bool, technical: bool, fx: bool}  $dimensions
     */
    private function missingReason(array $dimensions): string
    {
        $labels = [
            'futures' => '期貨淨空',
            'spot' => '現貨連續大賣',
            'technical' => '破月線且季線',
            'fx' => '台幣趨勢貶值',
        ];

        $missing = [];

        foreach ($dimensions as $key => $ok) {
            if (! $ok) {
                $missing[] = $labels[$key];
            }
        }

        return '未達四維共振，缺：'.implode('、', $missing);
    }
}
