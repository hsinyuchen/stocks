<?php

namespace App\Services\Fake;

use App\Contracts\ChipDataProvider;
use App\Data\ChipFlowData;
use Carbon\CarbonImmutable;

class FakeChipDataProvider implements ChipDataProvider
{
    /**
     * 確定性序列：外資連續買超且逐日遞增，投信小額買超，自營商賣超。
     * 供測試斷言方向與合計，數值本身無市場意義。
     *
     * @return list<ChipFlowData>
     */
    public function fetch(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        $bars = min($days, 20);
        $start = CarbonImmutable::parse('2026-07-01');
        $out = [];

        for ($i = 0; $i < $bars; $i++) {
            $foreign = 1_000_000 + $i * 100_000;
            $trust = 200_000;
            $dealer = -50_000;

            $out[] = new ChipFlowData(
                date: $start->addDays($i)->toDateString(),
                foreignNet: $foreign,
                trustNet: $trust,
                dealerNet: $dealer,
                totalNet: $foreign + $trust + $dealer,
            );
        }

        return $out;
    }
}
