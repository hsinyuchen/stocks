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
            $groups = $this->annualRevenueGroups($facts);

            if ($groups === []) {
                return OrderInventoryData::empty();
            }

            // dataAsOf 語意與正常路徑一致（見下方主回傳）：序列裡最新一筆的期間
            // 結束日。這裡沒有季度可取，改取年營收最新一個財政年度的期間結束日。
            $latest = $groups[count($groups) - 1];

            return new OrderInventoryData(
                market: 'us',
                annualRevenue: $this->stripEndDate($groups),
                dataAsOf: $latest['end'],
            );
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
        $fiscalFocus = $this->fiscalFocusByPeriod($facts);

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
                        // 決定整個 period 的 fy/fp，其餘欄位即使晚到也不會再覆蓋。查表見
                        // fiscalFocusByPeriod()，不可直接用這一列自己的 fy/fp（見該方法註解）。
                        $out[$period]['fiscal_year'] ??= $fiscalFocus[$period]['fy'] ?? null;
                        $out[$period]['fiscal_period'] ??= $fiscalFocus[$period]['fp'] ?? null;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * period（如 '2026Q1'）→ 該期間真正的申報焦點 (fy, fp)。
     *
     * 根因：SEC fact 的 fy／fp 是**申報文件層級**的欄位（DocumentFiscalYearFocus／
     * DocumentFiscalPeriodFocus），不是「這段期間所屬的財政年度」——一份 10-K
     * 裡的每一列（含兩年比較期、去年的四個季度）全部帶著同一組 fy／fp。若直接
     * 拿 framed 列自己的 fy／fp，通常會拿到把該期間當「去年同期比較數」列出的
     * 後續申報書，年度因此多算一到兩年（實測 NVDA：2025Q1 這個 frame 命中的是
     * 2026 年 10-Q 裡的比較期，自己帶 fy=2027；2023Q4 命中的是後續 10-K，自己
     * 帶 fp=FY／fy=2025）。
     *
     * 更棘手的是：SEC 的 companyfacts API 只在**它認為最具代表性的單一列**上
     * 標記 frame（實測確認：2025Q1 這段期間在原始資料裡出現兩次——一次是
     * 2025-05-28 申報、fy=2026 的當期揭露，一次是 2026-05-20 申報、fy=2027 的
     * 比較期重複列出——但只有後者帶 frame）。若只看帶 frame 的那一列，連
     * 「掃全部標籤找最早 filed」都救不回來，因為真正最早 filed 的那一列根本
     * 沒被標 frame。故正確做法分兩步：
     *
     *  1. 用帶 frame 的列取得該期間的實際 (start, end)（instant 欄位只有 end）
     *     ——frame 對「這是哪一段期間」仍然可信，不可信的只有它附帶的 fy／fp。
     *  2. 用這組 (start, end) 回查全部原始列（不限於帶 frame 的那一列），取
     *     **最早 filed** 的一列的 fy／fp——它是第一次把這段期間當「當期」揭露
     *     的申報書，而不是後續把它降級成比較期的申報書。
     *
     * 掃描範圍是全部 sec_tags（不只 revenue）：不分欄位都可能命中同一個
     * period，樣本越多越不容易被單一標籤的巧合誤導。
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, array{fy: ?int, fp: ?string}>
     */
    private function fiscalFocusByPeriod(array $facts): array
    {
        // 第一步：period → 實際 (start, end)。同一 period 字串理論上只對應一組
        // 日期，用第一個遇到的帶 frame 列即可。
        $ranges = [];

        foreach ((array) config('order_inventory.sec_tags', []) as $field => $tags) {
            $instant = in_array($field, self::INSTANT_FIELDS, true);

            foreach ((array) $tags as $tag) {
                $units = $facts[$tag]['units']['USD'] ?? null;

                if (! is_array($units)) {
                    continue;
                }

                foreach ($units as $row) {
                    $period = $this->periodFrom($row['frame'] ?? null, $instant);

                    if ($period === null || ! isset($row['end'])) {
                        continue;
                    }

                    $ranges[$period] ??= [
                        'start' => $instant ? null : ($row['start'] ?? null),
                        'end' => (string) $row['end'],
                    ];
                }
            }
        }

        // 第二步：依 (start, end) 回查全部原始列（不限帶 frame 的那一列），
        // 取最早 filed 的 fy／fp。
        $out = [];

        foreach ($ranges as $period => $range) {
            $candidates = [];

            foreach ((array) config('order_inventory.sec_tags', []) as $field => $tags) {
                $instant = in_array($field, self::INSTANT_FIELDS, true);

                foreach ((array) $tags as $tag) {
                    $units = $facts[$tag]['units']['USD'] ?? null;

                    if (! is_array($units)) {
                        continue;
                    }

                    foreach ($units as $row) {
                        if (! isset($row['end']) || (string) $row['end'] !== $range['end']) {
                            continue;
                        }

                        if (! $instant && ($row['start'] ?? null) !== $range['start']) {
                            continue;
                        }

                        // filed 缺席時退化成「陣列中先出現者勝出」——已知的 fallback，
                        // 不是刻意設計，SEC 實務回應一律帶 filed，尚未遇過需要更精確
                        // 判準的案例。
                        $candidates[] = [
                            'fy' => isset($row['fy']) ? (int) $row['fy'] : null,
                            'fp' => isset($row['fp']) ? (string) $row['fp'] : null,
                            'filed' => (string) ($row['filed'] ?? ''),
                        ];
                    }
                }
            }

            if ($candidates === []) {
                continue;
            }

            usort($candidates, static fn (array $a, array $b): int => $a['filed'] <=> $b['filed']);
            $out[$period] = ['fy' => $candidates[0]['fy'], 'fp' => $candidates[0]['fp']];
        }

        return $out;
    }

    /**
     * 年營收：依 (start, end) 分組，不依 fy 分組。
     *
     * 根因（與 fiscalFocusByPeriod() 同一個）：fy／fp 是申報文件層級欄位，不是
     * 「這段期間所屬的財政年度」。同一段期間會在後續申報書裡反覆以「去年同期
     * 比較數」的身分出現，每次都帶著申報當下的 fy——實測 NVDA 的 2018-01-29～
     * 2019-01-27 這段期間，依序在 2019／2020／2021 三份申報書裡出現，fy 分別是
     * 2019／2020／2021。依 fy 分組會把同一段期間灌到三個不同年度，年年錯位。
     *
     * 正確作法：
     *  1. 只收期間長度落在 330～400 天的列——擋掉混進來的季度列，以及財政
     *     年度變更公司的過渡期年報（stub period，通常短於一年）。不看 fp：
     *     fp 跟 fy 一樣是申報文件層級欄位，不可信。
     *  2. 依 (start, end) 分組。
     *  3. 該組真正的財政年度 = 該組**最早 filed** 那一列的 fy。
     *  4. 該組的營收 = 該組**最晚 filed** 那一列的 val（重編／restatement）。
     *
     * 標籤偏好順序必須跟 collect()（季度那條路）一致：年營收與季營收要來自
     * 同一個 XBRL 科目，否則兩者用不同標籤（各自排除的項目不同），四季相加
     * 對不上年營收卻無從解釋。故逐財政年度判斷——同一年度先看偏好序中第一個
     * 「有該年度資料」的標籤；filed 的新舊只在同一個標籤、同一組 (start,end)
     * 內部拿來判斷重編版本，不能跨標籤比較 filed 新舊（那樣年營收會跟著哪個
     * 標籤先申報而漂移，即使季營收仍固定用第一順位）。
     *
     * @param  array<string, mixed>  $facts
     * @return list<array{fiscal_year: int, revenue: float, end: string}>
     */
    private function annualRevenueGroups(array $facts): array
    {
        $tags = (array) config('order_inventory.sec_tags.revenue', []);

        // 第一步：逐標籤，依 (start, end) 分組，組內取最早 filed 的 fy、最晚 filed 的 val。
        $byTag = [];

        foreach ($tags as $tag) {
            $units = $facts[$tag]['units']['USD'] ?? null;

            if (! is_array($units)) {
                continue;
            }

            $groups = [];

            foreach ($units as $row) {
                if (! is_numeric($row['val'] ?? null) || ! isset($row['fy'], $row['start'], $row['end'])) {
                    continue;
                }

                $length = $this->daysBetween((string) $row['start'], (string) $row['end']);

                if ($length < 330 || $length > 400) {
                    continue;
                }

                // filed 缺席時退化成「陣列中先出現者勝出」（見 usort 的穩定排序）
                // ——已知的 fallback，不是刻意設計；SEC 實務回應一律帶 filed。
                $groups[$row['start'].'|'.$row['end']][] = [
                    'fy' => (int) $row['fy'],
                    'filed' => (string) ($row['filed'] ?? ''),
                    'val' => (float) $row['val'],
                    'end' => (string) $row['end'],
                ];
            }

            foreach ($groups as $rows) {
                usort($rows, static fn (array $a, array $b): int => $a['filed'] <=> $b['filed']);

                $year = $rows[0]['fy'];

                // 同一標籤照理一個財政年度只會分到一組 (start,end)；真的撞到時
                // 沿用「先出現者勝出」，與別處的偏好序規則一致。
                if (! isset($byTag[$tag][$year])) {
                    $byTag[$tag][$year] = [
                        'revenue' => $rows[count($rows) - 1]['val'],
                        'end' => $rows[0]['end'],
                    ];
                }
            }
        }

        // 第二步：逐年度依偏好序取值——先命中的標籤勝出，與 collect() 同規則。
        $best = [];

        foreach ($tags as $tag) {
            foreach ($byTag[$tag] ?? [] as $year => $entry) {
                if (! isset($best[$year])) {
                    $best[$year] = $entry;
                }
            }
        }

        ksort($best);

        $out = [];

        foreach ($best as $year => $entry) {
            $out[] = ['fiscal_year' => $year, 'revenue' => $entry['revenue'], 'end' => $entry['end']];
        }

        return $out;
    }

    /**
     * @return list<array{fiscal_year: int, revenue: float}>
     */
    private function annualRevenueFrom(array $facts): array
    {
        return $this->stripEndDate($this->annualRevenueGroups($facts));
    }

    /**
     * @param  list<array{fiscal_year: int, revenue: float, end: string}>  $groups
     * @return list<array{fiscal_year: int, revenue: float}>
     */
    private function stripEndDate(array $groups): array
    {
        return array_map(
            static fn (array $row): array => ['fiscal_year' => $row['fiscal_year'], 'revenue' => $row['revenue']],
            $groups,
        );
    }

    private function daysBetween(string $start, string $end): int
    {
        return (int) (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days;
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
