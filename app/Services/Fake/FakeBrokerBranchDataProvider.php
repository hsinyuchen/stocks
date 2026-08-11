<?php

namespace App\Services\Fake;

use App\Contracts\BrokerBranchDataProvider;
use App\Data\BrokerBranchFlowData;
use Carbon\CarbonImmutable;

/**
 * 確定性券商分點序列，供測試斷言主力摘要/連續性/集中度。
 *
 * 五家券商，各自每日固定同向淨額（連續買/賣超），數值本身無市場意義：
 * 9200 凱基大買、9100 群益中買、9600 富邦小買、1360 麥格理大賣、8560 新光中賣。
 */
class FakeBrokerBranchDataProvider implements BrokerBranchDataProvider
{
    /** @var list<array{id:string,name:string,net:int}> */
    private const BROKERS = [
        ['id' => '9200', 'name' => '凱基-台北', 'net' => 800_000],
        ['id' => '9100', 'name' => '群益金鼎', 'net' => 300_000],
        ['id' => '9600', 'name' => '富邦', 'net' => 50_000],
        ['id' => '1360', 'name' => '港商麥格理', 'net' => -700_000],
        ['id' => '8560', 'name' => '新光', 'net' => -200_000],
    ];

    /** @return list<BrokerBranchFlowData> */
    public function fetch(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        $bars = min($days, 15);
        $start = CarbonImmutable::parse('2026-07-01');
        $out = [];

        for ($i = 0; $i < $bars; $i++) {
            $date = $start->addDays($i)->toDateString();

            foreach (self::BROKERS as $broker) {
                $net = $broker['net'];
                // 讓 buy/sell 各有值且 net 正確；買超側 buy 大、賣超側 sell 大。
                $buy = $net >= 0 ? $net + 100_000 : 100_000;
                $sell = $net >= 0 ? 100_000 : -$net + 100_000;

                $out[] = new BrokerBranchFlowData(
                    date: $date,
                    brokerId: $broker['id'],
                    brokerName: $broker['name'],
                    buyShares: $buy,
                    sellShares: $sell,
                    netShares: $buy - $sell,
                );
            }
        }

        return $out;
    }
}
