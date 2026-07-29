<?php

namespace App\Services\Fake;

use App\Contracts\MarginDataProvider;
use App\Data\MarginFlowData;
use Carbon\CarbonImmutable;

class FakeMarginDataProvider implements MarginDataProvider
{
    /**
     * 確定性序列：融資餘額逐日增加（散戶加碼中）、融券小幅增加。
     *
     * 與 FakeChipDataProvider 的「外資連續買超」搭配時，交叉判定會落在
     * 「融資增 + 外資買」＝同步做多。要測其他象限的測試請自行建 stub。
     *
     * 數值本身無市場意義，只供斷言方向與計算。
     *
     * @return list<MarginFlowData>
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
            $balance = 10_000_000 + $i * 200_000;
            $short = 500_000 + $i * 10_000;

            $out[] = new MarginFlowData(
                date: $start->addDays($i)->toDateString(),
                marginBalance: $balance,
                marginChange: 200_000,
                // 使用率固定 10%，落在 usage_low 門檻上，測試要另外調整才會觸發。
                marginLimit: $balance * 10,
                shortBalance: $short,
                shortChange: 10_000,
                offsetLoanAndShort: 100_000,
            );
        }

        return $out;
    }
}
