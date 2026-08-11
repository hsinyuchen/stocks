<?php

namespace App\Services\BrokerBranch;

use App\Contracts\BrokerBranchDataProvider;
use App\Data\BrokerBranchFlowData;
use App\Models\Instrument;
use App\Support\BrokerBranchGate;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Cache;

/**
 * 券商分點主力摘要（快取優先，不落 DB 明細）。
 *
 * 券商分點每股每日有數十~數百家券商列，落 DB 會膨脹；改以 Cache 存「主力摘要」
 * （買超/賣超前 N 大＋連續性＋集中度），與 MarketInstitutionalService/FuturesDataService
 * 的純陣列 cache 模式一致。非台股、Sponsor 受限、無資料一律回 null（呼叫端 best-effort）。
 */
class BrokerBranchDataService
{
    public function __construct(private readonly BrokerBranchDataProvider $provider) {}

    /**
     * 某台股的券商分點主力摘要；無法取得（非台股/受限/無資料）回 null。
     *
     * @return array<string, mixed>|null
     */
    public function summaryFor(Instrument $instrument, ?int $days = null): ?array
    {
        if (! MarketResolver::isTaiwan($instrument->symbol)) {
            return null;
        }

        // 此 token 券商分點已知不可用（Sponsor 受限冷卻中）：直接降級，不查 cache 也不打。
        if (BrokerBranchGate::isUnavailable()) {
            return null;
        }

        $symbol = strtoupper($instrument->symbol);
        $cacheKey = "broker_branch:{$symbol}";

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            // 負快取（available=false）代表上次抓不到，短期內不重打。
            return ($cached['available'] ?? false) ? $cached : null;
        }

        $days = $days ?? (int) config('brokerbranch.history_days', 30);
        $rows = $this->provider->fetch($instrument->symbol, $days);

        if ($rows === []) {
            Cache::put($cacheKey, ['available' => false], now()->addMinutes(
                max(1, (int) config('brokerbranch.failure_cache_minutes', 30)),
            ));

            return null;
        }

        $summary = $this->buildSummary($rows, max(1, (int) config('brokerbranch.top_n', 5)));

        Cache::put($cacheKey, $summary, now()->addMinutes(
            max(1, (int) config('brokerbranch.cache_minutes', 720)),
        ));

        return $summary;
    }

    /**
     * 以近 N 日全券商明細聚合出主力摘要。
     *
     * @param  list<BrokerBranchFlowData>  $rows
     * @return array<string, mixed>
     */
    private function buildSummary(array $rows, int $topN): array
    {
        // 依券商聚合：累計淨額、逐日淨額序列（供連續性）、出現天數、名稱。
        $byBroker = [];
        $dates = [];

        foreach ($rows as $row) {
            $dates[$row->date] = true;
            $id = $row->brokerId;

            $byBroker[$id] ??= ['id' => $id, 'name' => $row->brokerName, 'total' => 0, 'daily' => []];
            $byBroker[$id]['name'] = $row->brokerName;
            $byBroker[$id]['total'] += $row->netShares;
            $byBroker[$id]['daily'][$row->date] = ($byBroker[$id]['daily'][$row->date] ?? 0) + $row->netShares;
        }

        $buyers = array_values(array_filter($byBroker, static fn (array $b): bool => $b['total'] > 0));
        $sellers = array_values(array_filter($byBroker, static fn (array $b): bool => $b['total'] < 0));

        usort($buyers, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        usort($sellers, static fn (array $a, array $b): int => $a['total'] <=> $b['total']);

        $totalBuy = array_sum(array_map(static fn (array $b): int => $b['total'], $buyers));
        $totalSell = abs(array_sum(array_map(static fn (array $b): int => $b['total'], $sellers)));

        $topBuyers = array_slice($buyers, 0, $topN);
        $topSellers = array_slice($sellers, 0, $topN);

        return [
            'available' => true,
            'as_of' => max(array_keys($dates)),
            'window_days' => count($dates),
            'top_buyers' => array_map(fn (array $b): array => $this->brokerEntry($b, 'buy'), $topBuyers),
            'top_sellers' => array_map(fn (array $b): array => $this->brokerEntry($b, 'sell'), $topSellers),
            'concentration' => [
                'buy_topn_ratio' => $this->ratio(array_sum(array_map(static fn (array $b): int => $b['total'], $topBuyers)), $totalBuy),
                'sell_topn_ratio' => $this->ratio(abs(array_sum(array_map(static fn (array $b): int => $b['total'], $topSellers))), $totalSell),
            ],
        ];
    }

    /**
     * 單一主力券商的摘要條目。
     *
     * @param  array{id:string,name:string,total:int,daily:array<string,int>}  $broker
     * @return array<string, mixed>
     */
    private function brokerEntry(array $broker, string $side): array
    {
        return [
            'broker' => $broker['name'],
            'broker_id' => $broker['id'],
            'net_shares' => $broker['total'],
            'days_active' => count($broker['daily']),
            'streak_days' => $this->streak($broker['daily'], $side),
        ];
    }

    /**
     * 該券商從最新交易日往回、連續同向（買超或賣超）的天數。淨額 0 視為中斷。
     *
     * @param  array<string, int>  $daily  date => net
     */
    private function streak(array $daily, string $side): int
    {
        ksort($daily);
        $nets = array_values($daily);
        $streak = 0;

        for ($i = count($nets) - 1; $i >= 0; $i--) {
            $sameSide = $side === 'buy' ? $nets[$i] > 0 : $nets[$i] < 0;

            if (! $sameSide) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /** 佔比（0..1，四捨五入 4 位）；分母為 0 回 null。 */
    private function ratio(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole, 4) : null;
    }
}
