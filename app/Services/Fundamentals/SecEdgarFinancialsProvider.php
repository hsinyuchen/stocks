<?php

namespace App\Services\Fundamentals;

use App\Contracts\CompanyFinancialsProvider;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 美股財報序列，來源為 SEC EDGAR 的 XBRL companyfacts。
 *
 * 免費、官方、免金鑰，且一檔一次呼叫即回全部科目（實測 NVDA 有 626 個
 * us-gaap 標籤），比 FinMind 需要 4–5 次划算。更重要的是它有存貨組成
 * （在製品、製成品），那是台股拿不到、而框架視為判斷方向最關鍵的細分。
 *
 * 兩個實測發現的坑：
 *  1. 標籤名稱因公司而異——NVDA 沒有原料的專屬標籤，故走 config 的偏好
 *     順序，全部落空時再以「總存貨 − 在製品 − 製成品」反推。
 *  2. 季度 frame 有缺口——NVDA 的 CostOfRevenue 缺 CY2025Q4。缺季一律留
 *     null，**不得以鄰季補值**：補值會讓 QoQ 變動失真，而變動量正是本
 *     框架的判斷依據。
 *
 * frame 命名：時點科目（資產負債表）帶 I 後綴如 CY2026Q1I，期間科目
 * （損益、現金流）不帶如 CY2026Q1。兩者不可混用。
 */
class SecEdgarFinancialsProvider implements CompanyFinancialsProvider
{
    /** 時點科目：取帶 I 後綴的 frame。 */
    private const INSTANT_FIELDS = [
        'inventories',
        'inventory_raw_materials',
        'inventory_work_in_process',
        'inventory_finished_goods',
        'accounts_receivable',
        'accounts_payable',
        'contract_liabilities',
    ];

    public function __construct(private readonly SecTickerCikResolver $cik) {}

    public function financials(string $symbol, int $months): OrderInventoryData
    {
        $cik = $this->cik->resolve($symbol);

        if ($cik === null) {
            return OrderInventoryData::empty();
        }

        $facts = $this->companyFacts($cik);

        if ($facts === []) {
            return OrderInventoryData::empty();
        }

        $byPeriod = $this->collect($facts);

        if ($byPeriod === []) {
            // 季度 frame 全缺不代表沒有資料——同一份 companyfacts 仍可能有年度
            // 申報，比照台股「只有月營收」的作法，能帶多少就帶多少。
            $annual = $this->annualRevenueFrom($facts);

            return $annual === []
                ? OrderInventoryData::empty()
                : new OrderInventoryData(market: 'us', annualRevenue: $annual);
        }

        ksort($byPeriod);
        $max = max(1, (int) config('order_inventory.max_quarters', 12));
        $byPeriod = array_slice($byPeriod, -$max, null, true);

        $quarters = [];
        $hasComposition = false;

        foreach ($byPeriod as $period => $values) {
            $quarter = $this->toQuarter((string) $period, $values);
            $hasComposition = $hasComposition
                || $quarter->inventoryWorkInProcess !== null
                || $quarter->inventoryFinishedGoods !== null;
            $quarters[] = $quarter;
        }

        $latest = $quarters[count($quarters) - 1];

        return new OrderInventoryData(
            quarters: $quarters,
            monthlyRevenue: [],          // 美股無月營收揭露制度
            market: 'us',
            industry: null,              // 美股改用存貨佔比啟發式，不抓 SIC
            inventoryCompositionAvailable: $hasComposition,
            dataAsOf: $latest->endDate,
            annualRevenue: $this->annualRevenueFrom($facts),
        );
    }

    /**
     * @return array<string, mixed> facts.us-gaap
     */
    private function companyFacts(string $cik): array
    {
        $config = (array) config('order_inventory.sec', []);
        $url = str_replace('{cik}', $cik, (string) ($config['company_facts_url'] ?? ''));

        try {
            $response = Http::withHeaders(['User-Agent' => (string) ($config['user_agent'] ?? '')])
                ->timeout((int) ($config['timeout_seconds'] ?? 40))
                ->get($url);
        } catch (Throwable $exception) {
            Log::warning('sec: company facts fetch failed', ['cik' => $cik, 'error' => $exception->getMessage()]);

            return [];
        }

        // 429/403 是暫時性拒絕，不代表這家公司沒資料——回空讓呼叫端走
        // 節流重試，而不是把它記成「無財報」。
        if (! $response->successful()) {
            Log::warning('sec: company facts rejected', ['cik' => $cik, 'status' => $response->status()]);

            return [];
        }

        return (array) $response->json('facts.us-gaap', []);
    }

    /**
     * 依 config 的標籤偏好順序，把各欄位的季度值收成 period => [field => val]。
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, array<string, mixed>>
     */
    private function collect(array $facts): array
    {
        $out = [];

        foreach ((array) config('order_inventory.sec_tags', []) as $field => $tags) {
            $instant = in_array($field, self::INSTANT_FIELDS, true);

            // 逐 period 補洞，不在「這個標籤曾命中過」就跳出整個別名鏈。
            // SEC 的申報實務會在某個年度換用另一個標籤（實測 NVDA 的第一順位
            // 標籤只覆蓋到 2021 年），整欄位跳出會讓換標籤之後的期間全部讀不到。
            // 偏好順序仍然成立——下面的 isset() 讓先命中的標籤勝出。
            foreach ((array) $tags as $tag) {
                $units = $facts[$tag]['units']['USD'] ?? null;

                if (! is_array($units)) {
                    continue;
                }

                foreach ($units as $row) {
                    $period = $this->periodFrom($row['frame'] ?? null, $instant);

                    if ($period === null || ! is_numeric($row['val'] ?? null)) {
                        continue;
                    }

                    // 偏好順序：先命中的標籤勝出，後續標籤不覆蓋。
                    if (! isset($out[$period][$field])) {
                        $out[$period][$field] = (float) $row['val'];
                        $out[$period]['end_date'] ??= isset($row['end']) ? (string) $row['end'] : null;
                        // 注意：fiscal_year / fiscal_period 是同一個 period slot 共用，
                        // 不分欄位——同一 period 底下第一個寫入的欄位（不一定是 revenue）
                        // 決定整個 period 的 fy/fp，其餘欄位即使晚到也不會再覆蓋。
                        $out[$period]['fiscal_year'] ??= isset($row['fy']) ? (int) $row['fy'] : null;
                        $out[$period]['fiscal_period'] ??= isset($row['fp']) ? (string) $row['fp'] : null;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * 年營收：取 fp = FY 的申報列，依 fiscal year 歸戶，同年取 filed 較晚者（重編）。
     *
     * 不用 frame：frame 是日曆期間配對，年度 frame 的年份未必等於公司的財政年度。
     * 也不由季度相加：SEC 的季度 frame 允許缺口，相加會少算。
     *
     * @param  array<string, mixed>  $facts
     * @return list<array{fiscal_year: int, revenue: float}>
     */
    private function annualRevenueFrom(array $facts): array
    {
        $best = [];

        foreach ((array) config('order_inventory.sec_tags.revenue', []) as $tag) {
            $units = $facts[$tag]['units']['USD'] ?? null;

            if (! is_array($units)) {
                continue;
            }

            foreach ($units as $row) {
                if (($row['fp'] ?? null) !== 'FY' || ! is_numeric($row['val'] ?? null) || ! isset($row['fy'])) {
                    continue;
                }

                $year = (int) $row['fy'];
                $filed = (string) ($row['filed'] ?? '');

                // 同一個財政年度可能有原始申報與重編，取申報日較晚的那一列。
                if (isset($best[$year]) && $best[$year]['filed'] >= $filed) {
                    continue;
                }

                $best[$year] = ['revenue' => (float) $row['val'], 'filed' => $filed];
            }
        }

        ksort($best);

        $out = [];

        foreach ($best as $year => $entry) {
            $out[] = ['fiscal_year' => $year, 'revenue' => $entry['revenue']];
        }

        return $out;
    }

    /**
     * frame 'CY2026Q1I' / 'CY2026Q1' → '2026Q1'；不符合預期形狀或
     * 時點/期間屬性不匹配時回 null。
     */
    private function periodFrom(mixed $frame, bool $instant): ?string
    {
        if (! is_string($frame) || ! preg_match('/^CY(\d{4})(Q[1-4])(I?)$/', $frame, $m)) {
            return null;
        }

        if ($instant !== ($m[3] === 'I')) {
            return null;
        }

        return $m[1].$m[2];
    }

    /**
     * @param  array<string, mixed>  $v
     */
    private function toQuarter(string $period, array $v): QuarterlyFinancials
    {
        $num = static fn (string $key): ?float => isset($v[$key]) && is_numeric($v[$key]) ? (float) $v[$key] : null;

        $total = $num('inventories');
        $wip = $num('inventory_work_in_process');
        $finished = $num('inventory_finished_goods');
        $raw = $num('inventory_raw_materials');

        // 專屬標籤優先；缺席時才以「總存貨 − 在製品 − 製成品」反推。
        // 任一組成缺席就無從反推——硬湊會把「未揭露」變成看似精確的數字。
        if ($raw === null && $total !== null && $wip !== null && $finished !== null) {
            $raw = $total - $wip - $finished;
        }

        return new QuarterlyFinancials(
            period: $period,
            endDate: isset($v['end_date']) ? (string) $v['end_date'] : null,
            revenue: $num('revenue'),
            costOfGoodsSold: $num('cost_of_goods_sold'),
            grossProfit: $num('gross_profit'),
            netIncome: $num('net_income'),
            inventories: $total,
            inventoryRawMaterials: $raw,
            inventoryWorkInProcess: $wip,
            inventoryFinishedGoods: $finished,
            accountsReceivable: $num('accounts_receivable'),
            accountsPayable: $num('accounts_payable'),
            accountsPayableRelatedParties: null,   // 美股無對應的關係人應付揭露標籤
            contractLiabilities: $num('contract_liabilities'),
            operatingCashFlow: $num('operating_cash_flow'),
            capex: $num('capex'),
            fiscalYear: isset($v['fiscal_year']) ? (int) $v['fiscal_year'] : null,
            fiscalPeriod: isset($v['fiscal_period']) ? (string) $v['fiscal_period'] : null,
        );
    }
}
