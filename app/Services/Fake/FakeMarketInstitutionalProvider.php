<?php

namespace App\Services\Fake;

use App\Contracts\MarketInstitutionalProvider;
use App\Data\MarketInstitutionalData;

/**
 * 確定性全市場三大法人快照，供測試與 fake driver。
 *
 * 情境：外資賣超（-407 億）、投信小買、自營賣超。數值單位為元，無市場意義。
 */
class FakeMarketInstitutionalProvider implements MarketInstitutionalProvider
{
    public function latest(): MarketInstitutionalData
    {
        return new MarketInstitutionalData(
            date: '2026-06-20',
            foreignNet: -40_715_743_790,
            trustNet: 1_500_000_000,
            dealerNet: -1_968_000_000,
        );
    }

    /**
     * 確定性外資現貨淨賣超序列，收在 -407 億（與 latest 一致）。
     *
     * @return list<array{date: string, net: int}>
     */
    public function foreignNetSeries(int $days): array
    {
        $series = [
            ['date' => '2026-06-16', 'net' => -18_000_000_000],
            ['date' => '2026-06-17', 'net' => -22_000_000_000],
            ['date' => '2026-06-18', 'net' => -31_000_000_000],
            ['date' => '2026-06-19', 'net' => -35_000_000_000],
            ['date' => '2026-06-20', 'net' => -40_715_743_790],
        ];

        return array_slice($series, -max(1, $days));
    }
}
