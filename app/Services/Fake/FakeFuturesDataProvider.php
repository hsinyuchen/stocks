<?php

namespace App\Services\Fake;

use App\Contracts\FuturesDataProvider;
use App\Data\FuturesMarketData;

/**
 * 確定性期貨快照，供測試與 fake driver。
 *
 * 情境：外資期貨淨空（-8,000 口）、投信小多、自營小空，選擇權 Put 略多於 Call
 * （P/C > 1，偏空避險）。數值無市場意義，只供斷言方向與計算。
 */
class FakeFuturesDataProvider implements FuturesDataProvider
{
    public function snapshot(): FuturesMarketData
    {
        return new FuturesMarketData(
            date: '2026-06-20',
            futuresClose: 22150.0,
            futuresOpenInterest: 92000,
            futuresVolume: 130000,
            foreignNetOi: -8000,
            trustNetOi: 1200,
            dealerNetOi: -300,
            optionPutOi: 165000,
            optionCallOi: 150000,
        );
    }

    /**
     * 確定性外資期貨淨留倉序列，收在 -8,000（與 snapshot 一致、未達翻空門檻）。
     *
     * fake driver 預設不觸發大盤翻空；需要觸發情境的測試自行綁定 stub provider。
     *
     * @return list<array{date: string, net: int}>
     */
    public function foreignNetOiSeries(int $days): array
    {
        $series = [
            ['date' => '2026-06-16', 'net' => -5000],
            ['date' => '2026-06-17', 'net' => -6500],
            ['date' => '2026-06-18', 'net' => -7200],
            ['date' => '2026-06-19', 'net' => -7600],
            ['date' => '2026-06-20', 'net' => -8000],
        ];

        return array_slice($series, -max(1, $days));
    }
}
