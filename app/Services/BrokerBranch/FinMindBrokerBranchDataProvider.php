<?php

namespace App\Services\BrokerBranch;

use App\Contracts\BrokerBranchDataProvider;
use App\Data\BrokerBranchFlowData;
use App\Support\BrokerBranchGate;
use App\Support\FinMindTokenResolver;
use App\Support\MarketResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * FinMind 券商分點進出（dataset TaiwanStockTradingDailyReportSecIdAgg，Sponsor 付費）。
 *
 * 刻意不引用全站 FinMindGate：券商分點受限走獨立的 BrokerBranchGate，避免一次
 * Sponsor 受限就把該 token 的免費功能（行情/三大法人）一起冷卻。token 用 per-user
 * resolver（Sponsor 使用者用自己的 token 就抓得到）。
 */
class FinMindBrokerBranchDataProvider implements BrokerBranchDataProvider
{
    private const ENDPOINT = 'https://api.finmindtrade.com/api/v4/data';

    private const DATASET = 'TaiwanStockTradingDailyReportSecIdAgg';

    public function __construct(
        private readonly FinMindTokenResolver $tokens,
        private readonly int $timeoutSeconds = 20,
    ) {}

    /** @return list<BrokerBranchFlowData> */
    public function fetch(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        // 此 token 券商分點已知不可用（Sponsor 受限冷卻中）：直接跳過，不再打。
        if (BrokerBranchGate::isUnavailable()) {
            return [];
        }

        $response = Http::timeout($this->timeoutSeconds)->get(self::ENDPOINT, array_filter([
            'dataset' => self::DATASET,
            'data_id' => MarketResolver::taiwanCode($symbol),   // 2330.TW → 2330
            'start_date' => now()->subDays($days)->toDateString(),
            'token' => $this->tokens->resolve() ?: null,
        ]));

        // Sponsor 受限：標記獨立守門（不連坐全站 FinMindGate），回空走降級。
        if ($this->sponsorLimited($response)) {
            BrokerBranchGate::markUnavailable();

            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $rows = $response->json('data');

        return is_array($rows) ? $this->normalize($rows) : [];
    }

    /**
     * 回應是否代表 Sponsor 付費牆/額度受限。認 402/429 與付費牆用語（含 free/upgrade/
     * sponsor/permission），與 FinMindGate 的判定同源但擴充 Sponsor 專屬用語。
     */
    private function sponsorLimited(Response $response): bool
    {
        if (in_array($response->status(), [402, 429], true)) {
            return true;
        }

        $msg = strtolower((string) $response->json('msg'));

        return $msg !== '' && (
            str_contains($msg, 'limit')
            || str_contains($msg, 'level is free')
            || str_contains($msg, 'upgrade')
            || str_contains($msg, 'exceed')
            || str_contains($msg, 'sponsor')
            || str_contains($msg, 'permission')
        );
    }

    /**
     * 上游每列為「某日某券商」的買賣量，轉為 BrokerBranchFlowData 並依日期升冪。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<BrokerBranchFlowData>
     */
    private function normalize(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $date = $row['date'] ?? null;
            $id = $row['securities_trader_id'] ?? null;

            if (! is_string($date) || $date === '' || $id === null || $id === '') {
                continue;
            }

            $buy = (int) round((float) ($row['buy_volume'] ?? 0));
            $sell = (int) round((float) ($row['sell_volume'] ?? 0));

            $out[] = new BrokerBranchFlowData(
                date: $date,
                brokerId: (string) $id,
                brokerName: (string) ($row['securities_trader'] ?? $id),
                buyShares: $buy,
                sellShares: $sell,
                netShares: $buy - $sell,
            );
        }

        usort($out, static fn (BrokerBranchFlowData $a, BrokerBranchFlowData $b): int => $a->date <=> $b->date);

        return $out;
    }
}
