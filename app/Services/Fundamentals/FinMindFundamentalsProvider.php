<?php

namespace App\Services\Fundamentals;

use App\Contracts\FundamentalsProvider;
use App\Data\FundamentalsData;
use App\Support\FinMindGate;
use App\Support\FinMindTokenResolver;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Http;

class FinMindFundamentalsProvider implements FundamentalsProvider
{
    private const ENDPOINT = 'https://api.finmindtrade.com/api/v4/data';

    public function __construct(
        private readonly FinMindTokenResolver $tokens,
        private readonly int $timeoutSeconds = 20,
    ) {}

    public function fetch(string $symbol): FundamentalsData
    {
        $dataId = MarketResolver::taiwanCode($symbol);   // 2330.TW → 2330
        $per = $this->rows('TaiwanStockPER', $dataId, now()->subDays(30)->toDateString());
        $fs = $this->rows('TaiwanStockFinancialStatements', $dataId, now()->subMonths(18)->toDateString());
        $bs = $this->rows('TaiwanStockBalanceSheet', $dataId, now()->subMonths(18)->toDateString());
        $mr = $this->rows('TaiwanStockMonthRevenue', $dataId, now()->subMonths(18)->toDateString());

        [$eps, $epsQuarter] = $this->ttmEps($fs);

        return new FundamentalsData(
            per: $this->latest($per, 'PER'),
            pbr: $this->latest($per, 'PBR'),
            dividendYield: $this->latest($per, 'dividend_yield'),
            eps: $eps,
            epsQuarter: $epsQuarter,
            roe: $this->roe($fs, $bs),
            revenue: $this->latestRevenue($mr)['revenue'] ?? null,
            revenueMonth: $this->latestRevenue($mr)['month'] ?? null,
            revenueYoy: $this->revenueYoy($mr),
            dataAsOf: $this->latestDate($per),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $dataset, string $dataId, string $startDate): array
    {
        // 免費層額度冷卻中：跳過。本方法一次分析被呼叫多次（PER/財報/資產/營收），
        // 冷卻一旦開啟，後續幾個 dataset 都會直接略過，不再逐一撞額度。
        if (FinMindGate::isTripped()) {
            return [];
        }

        $response = Http::timeout($this->timeoutSeconds)->get(self::ENDPOINT, array_filter([
            'dataset' => $dataset,
            'data_id' => $dataId,
            'start_date' => $startDate,
            'token' => $this->tokens->resolve() ?: null,
        ]));

        if (FinMindGate::limited($response) || $response->failed()) {
            return [];
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    /** PER 資料集：最新一列（依 date 排序）取指定欄。 */
    private function latest(array $rows, string $key): ?float
    {
        $row = $this->latestRow($rows);

        return $row !== null && isset($row[$key]) && is_numeric($row[$key]) ? (float) $row[$key] : null;
    }

    private function latestDate(array $rows): ?string
    {
        return $this->latestRow($rows)['date'] ?? null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function latestRow(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return $rows[count($rows) - 1];
    }

    /**
     * FS 值為單季（實測定論）。TTM EPS = 近四季 EPS 加總；季別 = 最新季 date。
     * 不足四季 → null。
     *
     * @param  list<array<string, mixed>>  $fs
     * @return array{0: ?float, 1: ?string}
     */
    private function ttmEps(array $fs): array
    {
        $eps = $this->seriesByQuarter($fs, 'EPS');

        if (count($eps) < 4) {
            return [null, array_key_last($eps) ?: null];
        }

        $lastFour = array_slice($eps, -4, 4, true);

        return [round(array_sum($lastFour), 4), array_key_last($lastFour)];
    }

    /**
     * ROE = TTM 淨利（近四季 IncomeAfterTaxes 加總）/ 最新季股東權益 × 100。
     * 淨利來自損益表 $fs；股東權益來自資產負債表 $bs（FinMind 損益表 dataset 不含 Equity）。
     * 股東權益優先 type=Equity，缺則 fallback EquityAttributableToOwnersOfParent。
     *
     * @param  list<array<string, mixed>>  $fs
     * @param  list<array<string, mixed>>  $bs
     */
    private function roe(array $fs, array $bs): ?float
    {
        $ni = $this->seriesByQuarter($fs, 'IncomeAfterTaxes');
        $equity = $this->seriesByQuarter($bs, 'Equity')
            ?: $this->seriesByQuarter($bs, 'EquityAttributableToOwnersOfParent');

        if (count($ni) < 4 || $equity === []) {
            return null;
        }

        $ttmNet = array_sum(array_slice($ni, -4, 4, true));
        $latestEquity = end($equity);

        return $latestEquity != 0.0 ? round($ttmNet / $latestEquity * 100, 4) : null;
    }

    /**
     * 依季別日期彙整某 type 的單季值（date => value，升冪）。
     *
     * @param  list<array<string, mixed>>  $fs
     * @return array<string, float>
     */
    private function seriesByQuarter(array $fs, string $type): array
    {
        $out = [];
        foreach ($fs as $row) {
            if (($row['type'] ?? null) === $type && is_numeric($row['value'] ?? null)) {
                $out[(string) $row['date']] = (float) $row['value'];
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * 月營收：用 revenue_year/revenue_month 欄位（非 date，date 落後一月）取最新月。
     *
     * @param  list<array<string, mixed>>  $mr
     * @return array{revenue: ?float, month: ?string}
     */
    private function latestRevenue(array $mr): array
    {
        $latest = $this->latestMonthRow($mr);

        if ($latest === null) {
            return ['revenue' => null, 'month' => null];
        }

        return [
            'revenue' => (float) $latest['revenue'],
            'month' => sprintf('%04d-%02d-01', (int) $latest['revenue_year'], (int) $latest['revenue_month']),
        ];
    }

    /**
     * 年增%：最新月 (Y, M) vs (Y-1, M)。缺去年同月 → null。
     *
     * @param  list<array<string, mixed>>  $mr
     */
    private function revenueYoy(array $mr): ?float
    {
        $latest = $this->latestMonthRow($mr);

        if ($latest === null) {
            return null;
        }

        $y = (int) $latest['revenue_year'];
        $m = (int) $latest['revenue_month'];

        foreach ($mr as $row) {
            if ((int) ($row['revenue_year'] ?? 0) === $y - 1 && (int) ($row['revenue_month'] ?? 0) === $m) {
                $prev = (float) $row['revenue'];

                return $prev != 0.0 ? round(((float) $latest['revenue'] / $prev - 1) * 100, 4) : null;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $mr */
    private function latestMonthRow(array $mr): ?array
    {
        $best = null;
        foreach ($mr as $row) {
            if (! isset($row['revenue_year'], $row['revenue_month'], $row['revenue'])) {
                continue;
            }
            $key = (int) $row['revenue_year'] * 100 + (int) $row['revenue_month'];
            if ($best === null || $key > $best['key']) {
                $best = ['key' => $key, 'row' => $row];
            }
        }

        return $best['row'] ?? null;
    }
}
