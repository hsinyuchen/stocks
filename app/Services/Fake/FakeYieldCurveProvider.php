<?php

namespace App\Services\Fake;

use App\Contracts\YieldCurveProvider;
use App\Data\YieldCurveData;

/**
 * 確定性殖利率曲線，供測試與 fake driver。
 *
 * 情境：10Y 緩步下行、3M 下行更快 → 牛陡（bull_steepening）且利差維持為正
 * （未倒掛）。這組合刻意**不**滿足大盤翻空的利率維度（需 level=bear 或倒掛），
 * 與 FakeFuturesDataProvider「fake driver 預設不觸發大盤翻空」的慣例一致；
 * 需要利空利率情境的測試自行綁定 stub provider。
 *
 * 數值無市場意義，只供斷言方向與計算。
 */
class FakeYieldCurveProvider implements YieldCurveProvider
{
    /** 產生足夠長的歷史，確保最長窗口（60）與倒掛回看（60）都有資料。 */
    private const BARS = 140;

    public function curve(array $tenors, int $days): YieldCurveData
    {
        $long = [];
        $short = [];

        for ($i = 0; $i < self::BARS; $i++) {
            $date = sprintf('2026-%02d-%02d', 1 + intdiv($i, 28), ($i % 28) + 1);

            // 10Y 每根 -0.5bp；3M 每根 -1.5bp → 10Y 下行（牛）、利差擴大（陡）。
            $long[$date] = 4.80 - $i * 0.005;
            $short[$date] = 4.20 - $i * 0.015;
        }

        $byTenor = [];

        foreach ($tenors as $key => $_symbol) {
            $byTenor[(string) $key] = match ((string) $key) {
                '3m' => $short,
                default => $long,
            };
        }

        return YieldCurveData::aligned($byTenor);
    }
}
