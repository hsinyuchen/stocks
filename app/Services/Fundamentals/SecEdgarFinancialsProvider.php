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

                        // filed 缺席時、以及 filed 相同時，都退化成「陣列中先出現者
                        // 勝出」——PHP 8 的 usort 是穩定排序，同值不會打亂原順序，
                        // 而 $candidates 的原順序即 config('order_inventory.sec_tags')
                        // 的標籤順序。已知的 fallback，不是刻意設計；SEC 實務回應
                        // 一律帶 filed 且同一 (start,end) 極少見多筆同日 filed，
                        // 尚未遇過需要更精確判準的案例。
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
     * 還有一個更根本的坑（實測才發現）：**同一份**申報書常常一次揭露最近
     * 三個財政年度的比較數，SEC 回應對這三組期間標的是同一個 fy（申報當下
     * 的年度）。例如 filed=2019-02-21 的那份 10-K，同時列出 2016-02-01～
     * 2017-01-29、2017-01-30～2018-01-28、2018-01-29～2019-01-27 三段期間，
     * 三段全部 fy=2019。若不修正，first-wins 只會留下最舊那組，把
     * 「FY2019＝6.91B」記成錯的，而真正的 FY2019＝11.72B 完全不出現。
     * 見 correctFiscalYearByFiling()。
     *
     * 正確作法：
     *  1. 只收期間長度落在 330～400 天的列——擋掉混進來的季度列，以及財政
     *     年度變更公司的過渡期年報（stub period，通常短於一年）。不看 fp：
     *     fp 跟 fy 一樣是申報文件層級欄位，不可信。
     *  2. 依 accn（申報書編號）修正每一列的 fy：同一 accn 內依 end 由新到舊
     *     排序，最新那組沿用原始 fy，往前每退一組年度就少一年。
     *  3. 依 (start, end) 分組。
     *  4. 該組真正的財政年度 = 該組**最早 filed** 那一列（修正後）的 fy。
     *  5. 該組的營收 = 該組**最晚 filed** 那一列的 val（重編／restatement）。
     *  6. 只保留最近 10 個財政年度，且丟棄「fy 未嚴格遞增」的組——見
     *     recentFiscalYears()，這一步是在防另一個實測發現、無法用演算法
     *     修掉的坑：FY2015 前後 SEC／公司申報慣例本身改過命名，同一顆
     *     fy 在古早年份代表的實際財政年度會偏移一年，且無法從資料本身
     *     判斷偏移量，寧可缺年也不要錯年。
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

        // 第一步：逐標籤，修正 fy 後依 (start, end) 分組，組內取最早 filed 的
        // fy、最晚 filed 的 val。
        $byTag = [];

        foreach ($tags as $tag) {
            $units = $facts[$tag]['units']['USD'] ?? null;

            if (! is_array($units)) {
                continue;
            }

            $rows = [];

            foreach ($units as $row) {
                if (! is_numeric($row['val'] ?? null) || ! isset($row['fy'], $row['start'], $row['end'])) {
                    continue;
                }

                $length = $this->daysBetween((string) $row['start'], (string) $row['end']);

                if ($length < 330 || $length > 400) {
                    continue;
                }

                $rows[] = [
                    'fy' => (int) $row['fy'],
                    'filed' => (string) ($row['filed'] ?? ''),
                    'val' => (float) $row['val'],
                    'start' => (string) $row['start'],
                    'end' => (string) $row['end'],
                    'accn' => isset($row['accn']) && $row['accn'] !== '' ? (string) $row['accn'] : null,
                ];
            }

            $groups = [];

            foreach ($this->correctFiscalYearByFiling($rows) as $row) {
                $groups[$row['start'].'|'.$row['end']][] = $row;
            }

            foreach ($groups as $groupRows) {
                // filed 缺席或相同時退化成「陣列中先出現者勝出」（見 usort 的
                // 穩定排序）——已知的 fallback，不是刻意設計；SEC 實務回應
                // 一律帶 filed。
                usort($groupRows, static fn (array $a, array $b): int => $a['filed'] <=> $b['filed']);

                $year = $groupRows[0]['fy'];

                // 實測不成立「同一標籤一個財政年度只會分到一組 (start,end)」：
                // NVDA 的 Revenues 標籤裡，2010-02-01|2011-01-30（真 FY2011，
                // 3,543,309,000）與 2009-01-26|2010-01-31 修正後都變成 fy=2010，
                // 後者先插入、前者整組被丟。目前被 10 年窗口遮住不影響輸出，
                // 撞到時沿用「先出現者勝出」。
                if (! isset($byTag[$tag][$year])) {
                    $byTag[$tag][$year] = [
                        'revenue' => $groupRows[count($groupRows) - 1]['val'],
                        'end' => $groupRows[0]['end'],
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

        return $this->recentFiscalYears($best);
    }

    /**
     * 修正「一份申報書同時揭露多個財政年度、卻共用同一個 fy」的問題。
     *
     * 同一個 accn（申報書編號）內，把期間依 end 由新到舊排序：最新那組
     * 沿用申報書本身的 fy，往前每退一組財政年度就少一年（去年同期比較數
     * 理所當然比當期少一個財政年度）。accn 缺席時（測試 fixture 或未來
     * 上游改動）每一列自成一組，不做任何偏移——與修正前行為一致。
     *
     * 這個偏移量跨 accn 不會互相打架——各自以自己申報書最新一期為基準
     * 往前退幾年，不影響第一步之後「跨 accn 取最早 filed」的判定。但若
     * 某份申報書本身的 fy 就偏移（fy 是申報文件層級欄位，見本檔案開頭
     * 對此問題的說明），該申報書內三段期間會**一致地偏移同一格**，這正是
     * 10 年窗口在擋的東西（實測 NVDA 的 0001045810-11-000015，filed
     * 2011-03-16、fy=2010、最新期間 end 2011-01-30，三段期間一起偏移）。
     *
     * @param  list<array{fy: int, filed: string, val: float, start: string, end: string, accn: ?string}>  $rows
     * @return list<array{fy: int, filed: string, val: float, start: string, end: string, accn: ?string}>
     */
    private function correctFiscalYearByFiling(array $rows): array
    {
        $byAccn = [];

        foreach ($rows as $index => $row) {
            $byAccn[$row['accn'] ?? "\0singleton{$index}"][] = $row;
        }

        $out = [];

        foreach ($byAccn as $group) {
            usort($group, static fn (array $a, array $b): int => $b['end'] <=> $a['end']);

            $latestFiscalYear = $group[0]['fy'];
            $latestEnd = $group[0]['end'];

            foreach ($group as $row) {
                // 用 end 的年距而非陣列位置：accn 內的年度期間可能不連續
                // （財政年度變更的過渡期 stub 被 330~400 天濾掉、或申報只列部分年度），
                // 位置式 -1 會把缺口後面的年度整批往新的方向錯配一年。
                $offset = (int) round($this->daysBetween($row['end'], $latestEnd) / 365);
                $row['fy'] = $latestFiscalYear - $offset;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * 只留最近 10 個財政年度，並丟棄「fy 沒有嚴格遞增」的組。
     *
     * 實測發現：FY2015 前後 SEC／NVDA 申報慣例本身改過——同一顆 fy 欄位在
     * 古早年份代表的實際財政年度會偏移一年（例：filed=2012-03-13、涵蓋
     * 2011-01-31～2012-01-29 的 10-K 帶 fy=2011，但公司對外稱那是 fiscal
     * 2012）。這不是本檔案演算法的錯，資料本身就這樣，而且無法從資料反推
     * 偏移量。只能限定 fy 的可信區間：
     *  1. 只輸出最近 10 個財政年度——目前資料下全部落在慣例可信的區間內。
     *  2. 依 end（期間結束日，事實、不受申報慣例影響）由舊到新排序後檢查
     *     fy 是否嚴格遞增；一旦某組的 fy 沒有比前一個保留下來的組大，直接
     *     丟棄整組，不嘗試猜測正確值——缺年在畫面上看得出來，錯年看不出來。
     *
     * 下一位維護者如果想拿掉「只留最近 10 年」這個上限：別，先確認 fy 的
     * 申報慣例有沒有隨時間繼續改過，這條界線就是為了擋掉尚未證實可信的
     * 古早資料。
     *
     * @param  array<int, array{revenue: float, end: string}>  $byYear  fiscal_year => entry
     * @return list<array{fiscal_year: int, revenue: float, end: string}>
     */
    private function recentFiscalYears(array $byYear): array
    {
        $rows = [];

        foreach ($byYear as $year => $entry) {
            $rows[] = ['fiscal_year' => $year, 'revenue' => $entry['revenue'], 'end' => $entry['end']];
        }

        usort($rows, static fn (array $a, array $b): int => $a['end'] <=> $b['end']);

        // 先截窗口再檢查：嚴格遞增檢查錨定最舊那筆，而最舊那筆正是本方法
        // docblock 說「fy 不可信」的區間；先檢查會讓一筆古早爛列把後面全部
        // 正確年度連坐丟棄。
        $rows = array_slice($rows, -10);

        $kept = [];
        $lastFiscalYear = null;

        foreach ($rows as $row) {
            if ($lastFiscalYear !== null && $row['fiscal_year'] <= $lastFiscalYear) {
                continue;
            }

            $kept[] = $row;
            $lastFiscalYear = $row['fiscal_year'];
        }

        return $kept;
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
