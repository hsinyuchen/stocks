<?php

namespace App\Services\Market;

use App\Contracts\MarketInstitutionalProvider;
use App\Data\MarketInstitutionalData;
use App\Support\FinMindGate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FinMind 全市場三大法人現貨買賣超。
 *
 * dataset 無 data_id（整體市場）。法人別為英文，沿用 ChipDataProvider 的分類慣例：
 * 外資含外資自營（Foreign_Dealer_Self），自營含自行買賣與避險兩本帳。
 */
class FinMindMarketInstitutionalProvider implements MarketInstitutionalProvider
{
    private const ENDPOINT = 'https://api.finmindtrade.com/api/v4/data';

    private const DATASET = 'TaiwanStockTotalInstitutionalInvestors';

    /** FinMind name → 三大法人分類。未列出者（含 total）忽略。 */
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
        private readonly int $lookbackDays = 14,
    ) {}

    public function latest(): MarketInstitutionalData
    {
        $rows = $this->rows();

        if ($rows === []) {
            return MarketInstitutionalData::empty();
        }

        $latestDate = max(array_map(static fn (array $r): string => (string) ($r['date'] ?? ''), $rows));
        $net = ['foreign' => null, 'trust' => null, 'dealer' => null];

        foreach ($rows as $row) {
            if (($row['date'] ?? null) !== $latestDate) {
                continue;
            }

            $bucket = self::CATEGORIES[$row['name'] ?? ''] ?? null;

            if ($bucket === null) {
                continue;
            }

            // 淨買賣超 = 買進金額 − 賣出金額（元）。同一 bucket（如外資＋外資自營）累加。
            $net[$bucket] = ($net[$bucket] ?? 0) + (int) ($row['buy'] ?? 0) - (int) ($row['sell'] ?? 0);
        }

        return new MarketInstitutionalData(
            date: $latestDate,
            foreignNet: $net['foreign'],
            trustNet: $net['trust'],
            dealerNet: $net['dealer'],
        );
    }

    public function foreignNetSeries(int $days): array
    {
        $days = max(1, $days);
        // 多抓些日曆天以涵蓋週末假日，確保拿到足夠交易日，最後只留尾端 $days 筆。
        $rows = $this->rows(max($this->lookbackDays, (int) ceil($days * 1.6) + 5));

        if ($rows === []) {
            return [];
        }

        // 外資含外資自營：同日兩本帳累加。
        $byDate = [];

        foreach ($rows as $row) {
            if ((self::CATEGORIES[$row['name'] ?? ''] ?? null) !== 'foreign') {
                continue;
            }

            $date = (string) ($row['date'] ?? '');

            if ($date === '') {
                continue;
            }

            $byDate[$date] = ($byDate[$date] ?? 0) + (int) ($row['buy'] ?? 0) - (int) ($row['sell'] ?? 0);
        }

        ksort($byDate);

        $series = [];

        foreach ($byDate as $date => $net) {
            $series[] = ['date' => $date, 'net' => $net];
        }

        return array_slice($series, -$days);
    }

    /**
     * 抓 dataset 原始 rows（best-effort，含免費層額度守門）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(?int $startDays = null): array
    {
        if (FinMindGate::isTripped()) {
            return [];
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)->get(self::ENDPOINT, array_filter([
                'dataset' => self::DATASET,
                'start_date' => now()->subDays($startDays ?? $this->lookbackDays)->toDateString(),
                'token' => $this->token ?: null,
            ]));

            if (FinMindGate::limited($response) || $response->failed()) {
                return [];
            }

            $rows = $response->json('data');
        } catch (Throwable $exception) {
            Log::warning('market institutional: fetch threw', ['error' => $exception->getMessage()]);

            return [];
        }

        return is_array($rows) ? $rows : [];
    }
}
