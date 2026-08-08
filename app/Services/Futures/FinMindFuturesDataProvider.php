<?php

namespace App\Services\Futures;

use App\Contracts\FuturesDataProvider;
use App\Data\FuturesMarketData;
use App\Support\FinMindGate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FinMind 台股期貨/選擇權籌碼。
 *
 * 三個 dataset 各自 best-effort：任一失敗只讓對應區塊為 null，不影響其餘。
 * 全部免費層即可取得（大額交易人、選擇權 VIX 需贊助等級，不在此列）。
 */
class FinMindFuturesDataProvider implements FuturesDataProvider
{
    private const ENDPOINT = 'https://api.finmindtrade.com/api/v4/data';

    /** FinMind 期貨法人別（中文）→ 本專案分類。 */
    private const FUTURES_INVESTORS = [
        '外資' => 'foreign',
        '投信' => 'trust',
        '自營商' => 'dealer',
    ];

    public function __construct(
        private readonly ?string $token,
        private readonly string $futuresId = 'TX',
        private readonly string $optionId = 'TXO',
        private readonly int $timeoutSeconds = 20,
        private readonly int $lookbackDays = 14,
    ) {}

    public function snapshot(): FuturesMarketData
    {
        $futures = $this->futuresDaily();
        $inst = $this->futuresInstitutional();
        $option = $this->optionInstitutional();

        return new FuturesMarketData(
            date: $inst['date'] ?? $futures['date'] ?? $option['date'] ?? null,
            futuresClose: $futures['close'] ?? null,
            futuresOpenInterest: $futures['open_interest'] ?? null,
            futuresVolume: $futures['volume'] ?? null,
            foreignNetOi: $inst['foreign'] ?? null,
            trustNetOi: $inst['trust'] ?? null,
            dealerNetOi: $inst['dealer'] ?? null,
            optionPutOi: $option['put'] ?? null,
            optionCallOi: $option['call'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $dataset, string $dataId, ?int $startDays = null): array
    {
        // 免費層額度冷卻中：跳過。snapshot() 會呼叫本方法三次（期貨/法人/選擇權），
        // 冷卻一開，後兩個直接略過。
        if (FinMindGate::isTripped()) {
            return [];
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)->get(self::ENDPOINT, array_filter([
                'dataset' => $dataset,
                'data_id' => $dataId,
                'start_date' => now()->subDays($startDays ?? $this->lookbackDays)->toDateString(),
                'token' => $this->token ?: null,
            ]));

            if (FinMindGate::limited($response) || $response->failed()) {
                Log::warning('futures: fetch failed', ['dataset' => $dataset, 'status' => $response->status()]);

                return [];
            }

            $rows = $response->json('data');

            return is_array($rows) ? $rows : [];
        } catch (Throwable $exception) {
            Log::warning('futures: fetch threw', ['dataset' => $dataset, 'error' => $exception->getMessage()]);

            return [];
        }
    }

    /**
     * 台指期近月契約的最新一日收盤/未平倉/成交量。
     *
     * 一天有多列：日盤（position）與盤後（after_market）、多個 contract_date，還有
     * 價差組合（contract_date 含 '/'）。取一般盤、排除價差組合，再取最新交易日的
     * 近月（最小 contract_date），代表台指期主力契約的未平倉。
     *
     * @return array<string, mixed>
     */
    private function futuresDaily(): array
    {
        $rows = $this->get('TaiwanFuturesDaily', $this->futuresId);

        $rows = array_filter($rows, static fn (array $r): bool => ($r['trading_session'] ?? '') === 'position'
            && is_string($r['contract_date'] ?? null)
            && ! str_contains((string) $r['contract_date'], '/'));

        if ($rows === []) {
            return [];
        }

        $latestDate = max(array_map(static fn (array $r): string => (string) $r['date'], $rows));
        $onLatest = array_filter($rows, static fn (array $r): bool => $r['date'] === $latestDate);
        // 近月＝最小 contract_date（如 202608），主力合約的未平倉最具代表性。
        usort($onLatest, static fn (array $a, array $b): int => strcmp((string) $a['contract_date'], (string) $b['contract_date']));
        $nearMonth = $onLatest[0];

        return [
            'date' => $latestDate,
            'close' => isset($nearMonth['close']) ? (float) $nearMonth['close'] : null,
            'open_interest' => isset($nearMonth['open_interest']) ? (int) $nearMonth['open_interest'] : null,
            'volume' => isset($nearMonth['volume']) ? (int) $nearMonth['volume'] : null,
        ];
    }

    /**
     * 三大法人台指期最新一日淨未平倉（多方餘額 − 空方餘額，單位：口）。
     *
     * @return array<string, mixed>
     */
    private function futuresInstitutional(): array
    {
        $rows = $this->get('TaiwanFuturesInstitutionalInvestors', $this->futuresId);

        if ($rows === []) {
            return [];
        }

        $latestDate = max(array_map(static fn (array $r): string => (string) $r['date'], $rows));
        $net = ['foreign' => null, 'trust' => null, 'dealer' => null];

        foreach ($rows as $row) {
            if (($row['date'] ?? null) !== $latestDate) {
                continue;
            }

            $bucket = self::FUTURES_INVESTORS[$row['institutional_investors'] ?? ''] ?? null;

            if ($bucket === null) {
                continue;
            }

            $net[$bucket] = (int) ($row['long_open_interest_balance_volume'] ?? 0)
                - (int) ($row['short_open_interest_balance_volume'] ?? 0);
        }

        return ['date' => $latestDate] + $net;
    }

    /**
     * 外資台指期淨未平倉（口）近 $days 個交易日序列。
     *
     * 用 TaiwanFuturesInstitutionalInvestors（與 futuresInstitutional 同源）逐日取外資
     * 多空餘額相減。多抓些日曆天（涵蓋週末與國定假日）確保拿到足夠交易日，最後只留
     * 尾端 $days 筆。best-effort：抓不到回 []。
     *
     * @return list<array{date: string, net: int}>
     */
    public function foreignNetOiSeries(int $days): array
    {
        $days = max(1, $days);
        // 每 5 個交易日約跨 7 個日曆天，另加緩衝，避免週末假日吃掉交易日。
        $lookback = max($this->lookbackDays, (int) ceil($days * 1.6) + 5);
        $rows = $this->get('TaiwanFuturesInstitutionalInvestors', $this->futuresId, $lookback);

        if ($rows === []) {
            return [];
        }

        $byDate = [];

        foreach ($rows as $row) {
            if (($row['institutional_investors'] ?? '') !== '外資') {
                continue;
            }

            $date = (string) ($row['date'] ?? '');

            if ($date === '') {
                continue;
            }

            $byDate[$date] = (int) ($row['long_open_interest_balance_volume'] ?? 0)
                - (int) ($row['short_open_interest_balance_volume'] ?? 0);
        }

        ksort($byDate);

        $series = [];

        foreach ($byDate as $date => $net) {
            $series[] = ['date' => $date, 'net' => $net];
        }

        return array_slice($series, -$days);
    }

    /**
     * 三大法人台指選擇權最新一日未平倉的 Put/Call 口數合計。
     *
     * 用「三大法人未平倉」而非全市場：全市場 P/C 需逐履約價的 TaiwanOptionDaily
     * 加總，資料量大且非本專案重點。法人選擇權部位偏向已足以佐證多空氛圍。
     *
     * @return array<string, mixed>
     */
    private function optionInstitutional(): array
    {
        $rows = $this->get('TaiwanOptionInstitutionalInvestors', $this->optionId);

        if ($rows === []) {
            return [];
        }

        $latestDate = max(array_map(static fn (array $r): string => (string) $r['date'], $rows));
        $call = null;
        $put = null;

        foreach ($rows as $row) {
            if (($row['date'] ?? null) !== $latestDate) {
                continue;
            }

            $oi = (int) ($row['long_open_interest_balance_volume'] ?? 0);

            if (($row['call_put'] ?? '') === '買權') {
                $call = ($call ?? 0) + $oi;
            } elseif (($row['call_put'] ?? '') === '賣權') {
                $put = ($put ?? 0) + $oi;
            }
        }

        return ['date' => $latestDate, 'call' => $call, 'put' => $put];
    }
}
