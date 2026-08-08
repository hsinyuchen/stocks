<?php

namespace App\Services\Margin;

use App\Contracts\MarginDataProvider;
use App\Data\MarginFlowData;
use App\Support\FinMindGate;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Http;

class FinMindMarginDataProvider implements MarginDataProvider
{
    private const ENDPOINT = 'https://api.finmindtrade.com/api/v4/data';

    private const DATASET = 'TaiwanStockMarginPurchaseShortSale';

    /** 上游以「張」為單位；本專案的籌碼資料一律存「股」，兩者要一致。 */
    private const SHARES_PER_LOT = 1000;

    public function __construct(
        private readonly ?string $token,
        private readonly int $timeoutSeconds = 20,
    ) {}

    /** @return list<MarginFlowData> */
    public function fetch(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        // 免費層額度冷卻中：跳過，走 best-effort 降級。
        if (FinMindGate::isTripped()) {
            return [];
        }

        $response = Http::timeout($this->timeoutSeconds)->get(self::ENDPOINT, array_filter([
            'dataset' => self::DATASET,
            'data_id' => MarketResolver::taiwanCode($symbol),   // 2330.TW → 2330
            'start_date' => now()->subDays($days)->toDateString(),
            'token' => $this->token ?: null,
        ]));

        if (FinMindGate::limited($response) || $response->failed()) {
            return [];
        }

        $rows = $response->json('data');

        return is_array($rows) ? $this->normalize($rows) : [];
    }

    /**
     * 上游每個交易日一列，欄位已是餘額，只需換算單位並算出增減。
     *
     * 增減用上游自己的「今日 − 昨日」欄位，而不是跟前一列相減：資料可能缺日
     * （停牌、上游漏抓），用相鄰列會把跨越缺口的變化誤算成單日變化。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<MarginFlowData>
     */
    private function normalize(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $date = $row['date'] ?? null;

            if (! is_string($date) || $date === '') {
                continue;
            }

            $marginToday = (int) ($row['MarginPurchaseTodayBalance'] ?? 0);
            $marginYesterday = (int) ($row['MarginPurchaseYesterdayBalance'] ?? 0);
            $shortToday = (int) ($row['ShortSaleTodayBalance'] ?? 0);
            $shortYesterday = (int) ($row['ShortSaleYesterdayBalance'] ?? 0);

            $out[] = new MarginFlowData(
                date: $date,
                marginBalance: $marginToday * self::SHARES_PER_LOT,
                marginChange: ($marginToday - $marginYesterday) * self::SHARES_PER_LOT,
                marginLimit: (int) ($row['MarginPurchaseLimit'] ?? 0) * self::SHARES_PER_LOT,
                shortBalance: $shortToday * self::SHARES_PER_LOT,
                shortChange: ($shortToday - $shortYesterday) * self::SHARES_PER_LOT,
                offsetLoanAndShort: (int) ($row['OffsetLoanAndShort'] ?? 0) * self::SHARES_PER_LOT,
            );
        }

        usort($out, static fn (MarginFlowData $a, MarginFlowData $b): int => $a->date <=> $b->date);

        return $out;
    }
}
