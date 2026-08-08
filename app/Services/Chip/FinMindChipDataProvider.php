<?php

namespace App\Services\Chip;

use App\Contracts\ChipDataProvider;
use App\Data\ChipFlowData;
use App\Support\FinMindGate;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Http;

class FinMindChipDataProvider implements ChipDataProvider
{
    private const ENDPOINT = 'https://api.finmindtrade.com/api/v4/data';

    private const DATASET = 'TaiwanStockInstitutionalInvestorsBuySell';

    /**
     * FinMind 的 name 欄位 → 本專案的三大法人分類。
     *
     * 依證交所慣例，「外資」含外資自營商（Foreign_Dealer_Self），「自營商」
     * 含自行買賣與避險兩本帳。未列出的分類一律忽略，不併入合計——上游若
     * 新增分類，寧可少計也不要把未知資料算進買賣超。
     */
    private const CATEGORIES = [
        'Foreign_Investor' => 'foreign',
        'Foreign_Dealer_Self' => 'foreign',
        'Investment_Trust' => 'trust',
        'Dealer_self' => 'dealer',
        'Dealer_Hedging' => 'dealer',
    ];

    public function __construct(
        private readonly ?string $token,
        private readonly int $timeoutSeconds = 20,
    ) {}

    /** @return list<ChipFlowData> */
    public function fetch(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        // 免費層額度冷卻中：直接跳過，交由呼叫端走 best-effort 降級。
        if (FinMindGate::isTripped()) {
            return [];
        }

        $response = Http::timeout($this->timeoutSeconds)->get(self::ENDPOINT, array_filter([
            'dataset' => self::DATASET,
            'data_id' => MarketResolver::taiwanCode($symbol),   // 2330.TW → 2330
            'start_date' => now()->subDays($days)->toDateString(),
            'token' => $this->token ?: null,
        ]));

        // 撞到額度/等級上限即開啟全站冷卻；一般失敗維持原本的 best-effort 回空。
        if (FinMindGate::limited($response) || $response->failed()) {
            return [];
        }

        $rows = $response->json('data');

        return is_array($rows) ? $this->aggregate($rows) : [];
    }

    /**
     * 上游每個交易日回多列（每列一種法人分類），此處彙整為每日一筆淨額。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<ChipFlowData>
     */
    private function aggregate(array $rows): array
    {
        $byDate = [];

        foreach ($rows as $row) {
            $bucket = self::CATEGORIES[$row['name'] ?? ''] ?? null;
            $date = $row['date'] ?? null;

            if ($bucket === null || ! is_string($date) || $date === '') {
                continue;
            }

            $byDate[$date] ??= ['foreign' => 0, 'trust' => 0, 'dealer' => 0];
            $byDate[$date][$bucket] += (int) ($row['buy'] ?? 0) - (int) ($row['sell'] ?? 0);
        }

        ksort($byDate);

        $out = [];

        foreach ($byDate as $date => $net) {
            $out[] = new ChipFlowData(
                date: $date,
                foreignNet: $net['foreign'],
                trustNet: $net['trust'],
                dealerNet: $net['dealer'],
                totalNet: $net['foreign'] + $net['trust'] + $net['dealer'],
            );
        }

        return $out;
    }
}
