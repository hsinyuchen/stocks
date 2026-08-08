<?php

namespace App\Services\Futures;

use Illuminate\Support\Carbon;

/**
 * 大盤期貨翻空研判（外資期貨籌碼維度）。
 *
 * 依實戰檢核表 checklist #1：外資台指期淨空單「連續」站上門檻才算翻空——單日淨空
 * 常是日常避險或結算轉倉，不足採信。並排除台指期結算週（第三個星期三）前後的轉倉
 * 干擾：近月空單大量平倉/遠月轉倉會讓當週口數失真。
 *
 * 這只是「期貨籌碼」一個維度。真正的市場轉空須配合現貨賣壓、匯率與技術面共振
 * （見方法論指南）；本偵測器刻意只回報單一維度，交由使用者綜合研判。
 */
class MarketFuturesFlipDetector
{
    public function __construct(private readonly FuturesDataService $futures) {}

    /**
     * 回傳研判結果。triggered 為 true 才代表期貨籌碼面翻空成立。
     *
     * @return array{triggered: bool, reason: ?string, latest_date: ?string, net: ?int, streak_days: int, threshold_lots: int}
     */
    public function detect(): array
    {
        $config = (array) config('alerts.market_futures_flip', []);
        $streakDays = max(1, (int) ($config['streak_days'] ?? 3));
        $thresholdLots = (int) ($config['net_short_lots'] ?? 25000);
        $excludeSettlement = (bool) ($config['exclude_settlement'] ?? true);

        $base = [
            'triggered' => false,
            'reason' => null,
            'latest_date' => null,
            'net' => null,
            'streak_days' => $streakDays,
            'threshold_lots' => $thresholdLots,
        ];

        // 多取幾筆做為安全緩衝（例如尾端剛好落在資料缺日）。
        $series = $this->futures->foreignNetOiSeries($streakDays + 3);

        if (count($series) < $streakDays) {
            return ['reason' => '資料不足，無法判定'] + $base;
        }

        $window = array_slice($series, -$streakDays);
        $latest = $window[count($window) - 1];
        $result = array_merge($base, ['latest_date' => $latest['date'], 'net' => $latest['net']]);

        // 淨空達標：net ≤ -thresholdLots。任一日未達標即中斷連續。
        foreach ($window as $day) {
            if ($day['net'] > -$thresholdLots) {
                return array_merge($result, ['reason' => '外資期貨淨空未連續站上門檻']);
            }
        }

        if ($excludeSettlement && $this->inSettlementWindow($latest['date'])) {
            return array_merge($result, ['reason' => '結算週轉倉干擾，暫不判定']);
        }

        return array_merge($result, ['triggered' => true]);
    }

    /**
     * 日期是否落在台指期結算週干擾區間：結算日（該月第三個星期三）與其前 2 個交易日。
     *
     * 以日曆日近似交易日（第三個星期三往前 2 天＝同週一），未計國定假日；寧可在結算週
     * 附近少觸發（安全側），也不要在轉倉雜訊上誤報翻空。
     */
    private function inSettlementWindow(string $date): bool
    {
        $day = Carbon::parse($date);
        $settlement = $day->copy()->startOfMonth()->nthOfMonth(3, Carbon::WEDNESDAY);

        if (! $settlement instanceof Carbon) {
            return false;
        }

        return $day->betweenIncluded($settlement->copy()->subDays(2), $settlement);
    }
}
