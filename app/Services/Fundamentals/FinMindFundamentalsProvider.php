<?php

namespace App\Services\Fundamentals;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FundamentalsProvider;
use App\Data\FundamentalsData;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Support\FinMindGate;
use App\Support\FinMindTokenResolver;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Http;

class FinMindFundamentalsProvider implements CompanyFinancialsProvider, FundamentalsProvider
{
    private const ENDPOINT = 'https://api.finmindtrade.com/api/v4/data';

    /**
     * 同一次請求內的 rows() 結果快取："dataset|dataId|startDate" => 列。
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $memo = [];

    private ?string $memoDataId = null;

    public function __construct(
        private readonly FinMindTokenResolver $tokens,
        private readonly int $timeoutSeconds = 20,
        private readonly ?TaiwanIndustryResolver $industry = null,
    ) {}

    /** 共用 dataset 的回溯起始日。估值與序列必須用同一個值，memo 才命中。 */
    private function historyStart(?int $months = null): string
    {
        $months ??= (int) config('order_inventory.history_months', 30);

        return now()->subMonths(max(1, $months))->toDateString();
    }

    public function fetch(string $symbol): FundamentalsData
    {
        $dataId = MarketResolver::taiwanCode($symbol);   // 2330.TW → 2330
        // 三個共用 dataset 的起始日必須與 financials() 一致，否則 memo key 不同、
        // 同一份資料會被抓兩次。視窗放寬不影響估值：ttmEps()/roe() 取最新四季，
        // latestRevenue()/revenueYoy() 依年月配對，多回幾列結果不變。
        $start = $this->historyStart();
        $per = $this->rows('TaiwanStockPER', $dataId, now()->subDays(30)->toDateString());
        $fs = $this->rows('TaiwanStockFinancialStatements', $dataId, $start);
        $bs = $this->rows('TaiwanStockBalanceSheet', $dataId, $start);
        $mr = $this->rows('TaiwanStockMonthRevenue', $dataId, $start);

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

    public function financials(string $symbol, int $months): OrderInventoryData
    {
        $dataId = MarketResolver::taiwanCode($symbol);
        $start = $this->historyStart($months);

        $bs = $this->rows('TaiwanStockBalanceSheet', $dataId, $start);
        $fs = $this->rows('TaiwanStockFinancialStatements', $dataId, $start);
        $cf = $this->rows('TaiwanStockCashFlowsStatement', $dataId, $start);
        $mr = $this->rows('TaiwanStockMonthRevenue', $dataId, $start);

        $fields = (array) config('order_inventory.finmind_fields', []);
        $byDate = [];

        // 三個 dataset 的列形狀相同（date / type / value），依季末日歸戶。
        foreach ([$bs, $fs, $cf] as $rows) {
            foreach ($rows as $row) {
                $date = (string) ($row['date'] ?? '');
                $type = (string) ($row['type'] ?? '');
                $value = $row['value'] ?? null;

                if ($date === '' || $type === '' || ! is_numeric($value)) {
                    continue;
                }

                $field = array_search($type, $fields, true);

                if ($field !== false) {
                    $byDate[$date][$field] = (float) $value;
                }
            }
        }

        if ($byDate === []) {
            return OrderInventoryData::empty();
        }

        ksort($byDate);
        $max = max(1, (int) config('order_inventory.max_quarters', 12));
        $byDate = array_slice($byDate, -$max, null, true);

        $quarters = [];

        foreach ($byDate as $date => $values) {
            $quarters[] = $this->toQuarter((string) $date, $values);
        }

        return new OrderInventoryData(
            quarters: $quarters,
            monthlyRevenue: $this->monthlyRevenueSeries($mr),
            market: 'tw',
            industry: $this->industry?->resolve($symbol),
            // 台股財報附註未公開於資料源，存貨組成恆不可得。這是與美股的
            // 關鍵差異：那邊是實測數字，這邊只能靠代理訊號推論。
            inventoryCompositionAvailable: false,
            dataAsOf: array_key_last($byDate),
        );
    }

    /**
     * @param  array<string, float>  $values
     */
    private function toQuarter(string $date, array $values): QuarterlyFinancials
    {
        $num = static fn (string $key): ?float => isset($values[$key]) ? (float) $values[$key] : null;
        $capex = $num('capex');

        return new QuarterlyFinancials(
            period: $this->periodFromDate($date),
            endDate: $date,
            revenue: $num('revenue'),
            costOfGoodsSold: $num('cost_of_goods_sold'),
            grossProfit: $num('gross_profit'),
            netIncome: $num('net_income'),
            inventories: $num('inventories'),
            accountsReceivable: $num('accounts_receivable'),
            accountsPayable: $num('accounts_payable'),
            accountsPayableRelatedParties: $num('accounts_payable_related_parties'),
            contractLiabilities: $num('contract_liabilities'),
            operatingCashFlow: $num('operating_cash_flow'),
            // 現金流的資本支出為流出、原值為負；框架以正值論述其規模。
            capex: $capex === null ? null : abs($capex),
        );
    }

    /** '2026-06-30' → '2026Q2' */
    private function periodFromDate(string $date): string
    {
        $month = (int) substr($date, 5, 2);

        return substr($date, 0, 4).'Q'.max(1, (int) ceil($month / 3));
    }

    /**
     * 同 latestRevenue()/latestMonthRow()：用 revenue_year/revenue_month 定位所屬月份，
     * 不用 date（date 落後所屬營收月一個月，用它會讓整條序列標錯月）。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{month: string, revenue: float, yoy: ?float}>
     */
    private function monthlyRevenueSeries(array $rows): array
    {
        $byMonth = [];

        foreach ($rows as $row) {
            $revenue = $row['revenue'] ?? null;

            if (! isset($row['revenue_year'], $row['revenue_month']) || ! is_numeric($revenue)) {
                continue;
            }

            $month = sprintf('%04d-%02d-01', (int) $row['revenue_year'], (int) $row['revenue_month']);
            $byMonth[$month] = (float) $revenue;
        }

        ksort($byMonth);
        $out = [];
        $values = array_values($byMonth);
        $months = array_keys($byMonth);

        foreach ($months as $i => $month) {
            // 年增率需同月去年值，序列不足 13 筆時該筆為 null，不猜。
            $yoy = $i >= 12 && $values[$i - 12] > 0
                ? ($values[$i] - $values[$i - 12]) / $values[$i - 12]
                : null;

            $out[] = ['month' => $month, 'revenue' => $values[$i], 'yoy' => $yoy];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $dataset, string $dataId, string $startDate): array
    {
        // 免費層額度冷卻中：跳過。本方法一次分析被呼叫多次（PER/財報/資產/營收），
        // 冷卻一旦開啟，後續幾個 dataset 都會直接略過，不再逐一撞額度。
        // 短路必須在 memo 之前，且回的 [] 不寫入 memo——否則額度冷卻在同一次請求
        // 中途結束時，後續呼叫仍會拿到快取的空陣列。
        if (FinMindGate::isTripped()) {
            return [];
        }

        // 本實例同時服務估值與序列，兩邊有三個 dataset 重複。以實例陣列記憶，
        // 換標的（$dataId 變動）時整個清空：常駐 queue worker 會用同一個
        // singleton 跑過整個股池，不清會無限成長，也會跨日拿到陳舊資料。
        if ($this->memoDataId !== $dataId) {
            $this->memo = [];
            $this->memoDataId = $dataId;
        }

        $key = "$dataset|$dataId|$startDate";

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
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

        return $this->memo[$key] = is_array($data) ? $data : [];
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
