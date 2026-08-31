<?php

namespace App\Services\FinancialStatements\Sec;

use App\Data\FiscalYearBoundary;
use App\Enums\PeriodType;

/**
 * 從 companyfacts 推出公司自己的財政年曆。
 *
 * 為什麼不用 SEC 的 frame：frame 的覆蓋率逐 tag 不一致（實測 RGTI 的 Revenues
 * 只有 7 個季 frame、最新停在 CY2023Q1，GrossProfit 卻有 17 個、到 CY2026Q2），
 * 照它做會產出「有毛利、沒營收」的表。
 *
 * 為什麼不用「期間結束日貼到最近的日曆季末」：**那條規則錯了，不要回頭用。**
 * 曾用 27,195 筆有 frame 的列驗證出零例外，但 SEC 正是把對不齊的列不給 frame，
 * 樣本天生排除了會失敗的形狀。實測 COST（12/12/12/16 週制）的 16／17 週 Q4 會被
 * 長度窗丟棄，放寬窗之後又會撞號 18 次。
 */
class SecFiscalCalendar
{
    /**
     * 全部財政年度與過渡期，依 end 舊→新。
     *
     * @param  array<string, mixed>  $facts  decode 後的 companyfacts
     * @return list<FiscalYearBoundary>
     */
    public function boundaries(array $facts): array
    {
        $rows = $this->annualCandidates($facts);

        if ($rows === []) {
            return [];
        }

        $groups = $this->groupByEnd($rows);
        $median = $this->medianLength($groups);
        $deviation = (int) config('financial_statements.stub_deviation_days');

        $boundaries = [];

        foreach ($groups as $group) {
            $winner = $this->pickWinner($group, $median);
            $fiscalYear = $this->fiscalYearFor($winner, $rows);
            $isStub = abs($this->lengthOf($winner) - $median) > $deviation;

            $boundaries[] = new FiscalYearBoundary(
                fiscalYear: $fiscalYear,
                start: $winner['start'],
                end: $winner['end'],
                type: $isStub ? PeriodType::Stub : PeriodType::Annual,
                stubSlot: $isStub ? 1 : 0,
            );

            $slot = $isStub ? 2 : 1;

            foreach ($this->losers($group, $winner) as $loser) {
                $boundaries[] = new FiscalYearBoundary(
                    fiscalYear: $fiscalYear,
                    start: $loser['start'],
                    end: $loser['end'],
                    type: PeriodType::Stub,
                    stubSlot: $slot++,
                );
            }
        }

        usort($boundaries, fn (FiscalYearBoundary $a, FiscalYearBoundary $b) => [$a->end, $a->stubSlot] <=> [$b->end, $b->stubSlot]);

        return array_values($boundaries);
    }

    /**
     * 尚未申報 10-K 的進行中財政年度，暫定邊界。
     *
     * 這個邊界不進 boundaries() 的回傳值，因為它是**推測**出來的，不是年報佐證的
     * 事實——起點確定（上一年度結束的次日），但 end 只是拿歷史年度長度中位數
     * 隨便估的，真正的 end 要等 10-K 出來才知道。混進 boundaries() 會讓呼叫端
     * 無法分辨哪個年度有實據、哪個是猜的，這正是本專案「fy 錯位兩年而測試全綠」
     * 那類 bug 的溫床。
     *
     * 呼叫端使用這個邊界時必須遵守：
     * - **不推導 Q4**：沒有年報就沒有全年總額，SecQuarterDeriver::deriveIncome()
     *   的 $annual 無從取得，硬推只會拿推測的 end 去減，產出垃圾。
     * - **不產出年度列**：這個年度的 PeriodType::Annual 財報列並不存在。
     * - **只取季度**：交給 SecQuarterChain::chain() 鏈，鏈出幾季算幾季
     *   （通常 1~3 季，美股不單獨申報 Q4）。
     * - 現金流照常走 SecCashFlowDiffer——YTD 差分只看年度內相鄰兩期，不需要年報。
     *
     * 純函式：相同 $facts 必得相同輸出，**不讀系統時間**。「這個年度是否還在
     * 進行中」只能由資料本身回答（有沒有超出上一年度邊界、且落在合理季度長度
     * 內的新列），用 now() 猜會讓輸出隨執行時間改變，也會讓 fixture 測試腐化。
     *
     * @param  array<string, mixed>  $facts  decode 後的 companyfacts
     */
    public function inProgress(array $facts): ?FiscalYearBoundary
    {
        $boundaries = $this->boundaries($facts);

        $last = null;
        foreach ($boundaries as $boundary) {
            if ($boundary->type === PeriodType::Annual) {
                $last = $boundary;
            }
        }

        if ($last === null) {
            return null;
        }

        $start = date('Y-m-d', strtotime($last->end.' +1 day'));
        $length = $this->medianAnnualLength($boundaries);
        // lengthDays() 是 end-start 的天數差（不是含頭尾的天數），
        // 所以這裡要加 $length 天而非 $length-1 天，end 才會回推出同樣的 lengthDays()。
        $end = date('Y-m-d', strtotime($start.' +'.$length.' days'));

        if (! $this->hasNewQuarterFiledSince($facts, $last->end, $start)) {
            return null;
        }

        return new FiscalYearBoundary(
            fiscalYear: $last->fiscalYear + 1,
            start: $start,
            end: $end,
            type: PeriodType::Annual,
            stubSlot: 0,
        );
    }

    /**
     * 全部 Annual 邊界（不含 Stub）長度的中位數，偶數筆時的慣例與 medianLength() 一致。
     *
     * 不可用 medianLength()：它吃的是分組前的候選列並依「組內是否有歧義」篩選，
     * 這裡要的是「已經勝出、坐實成 Annual 的邊界」本身的長度分布。
     *
     * @param  list<FiscalYearBoundary>  $boundaries
     */
    private function medianAnnualLength(array $boundaries): int
    {
        $lengths = [];

        foreach ($boundaries as $boundary) {
            if ($boundary->type === PeriodType::Annual) {
                $lengths[] = $boundary->lengthDays();
            }
        }

        sort($lengths);
        $mid = intdiv(count($lengths), 2);

        return count($lengths) % 2 === 1
            ? $lengths[$mid]
            : (int) round(($lengths[$mid - 1] + $lengths[$mid]) / 2);
    }

    /**
     * 進行中年度是否已有新資料佐證——沒有的話回一個空殼邊界只會讓下游白跑一趟。
     *
     * 掃 us-gaap 全部 tag、全部 unit（不只 USD：EPS 之類的 USD/shares 也要算數，
     * 只要曾經有一列落在合理的季度長度、且確實延伸到上一年度之後即可）。
     *
     * @param  array<string, mixed>  $facts
     */
    private function hasNewQuarterFiledSince(array $facts, string $lastEnd, string $provisionalStart): bool
    {
        $min = (int) config('financial_statements.quarter_days.min');
        $max = (int) config('financial_statements.quarter_days.max');
        $tolerance = (int) config('financial_statements.date_tolerance_days') * 86400;
        $lastEndTs = strtotime($lastEnd);
        $earliestAllowedStart = strtotime($provisionalStart) - $tolerance;

        foreach (($facts['facts']['us-gaap'] ?? []) as $def) {
            foreach (($def['units'] ?? []) as $rows) {
                foreach ($rows as $row) {
                    if (! isset($row['start'], $row['end'])) {
                        continue;
                    }

                    $startTs = strtotime($row['start']);
                    $endTs = strtotime($row['end']);
                    $days = (int) round(($endTs - $startTs) / 86400);

                    if ($days < $min || $days > $max) {
                        continue;
                    }
                    if ($endTs <= $lastEndTs) {
                        continue;
                    }
                    if ($startTs < $earliestAllowedStart) {
                        continue;
                    }

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 年度長度、且來自年報類 form 的列，依 (start,end) 去重取最早 filed。
     *
     * form 白名單不可省：8-K 也可能帶 330–400 天的期間，讓它進來會污染年曆。
     *
     * @return list<array<string, mixed>>
     */
    private function annualCandidates(array $facts): array
    {
        $forms = array_flip((array) config('financial_statements.annual_forms'));
        $min = (int) config('financial_statements.annual_days.min');
        $max = (int) config('financial_statements.annual_days.max');

        $out = [];

        foreach (($facts['facts']['us-gaap'] ?? []) as $def) {
            foreach (($def['units'] ?? []) as $unit => $rows) {
                if ($unit !== 'USD') {
                    continue;
                }

                foreach ($rows as $row) {
                    if (! isset($row['start'], $row['end'], $row['filed'], $row['form'], $row['fy'])) {
                        continue;
                    }
                    if (! isset($forms[$row['form']])) {
                        continue;
                    }

                    $days = $this->lengthOf($row);

                    if ($days < $min || $days > $max) {
                        continue;
                    }

                    $key = $row['start'].'~'.$row['end'];

                    if (! isset($out[$key]) || $row['filed'] < $out[$key]['filed']) {
                        $out[$key] = $row;
                    }
                }
            }
        }

        return array_values($out);
    }

    /**
     * 依 end 聚合，**非傳遞**。
     *
     * 以組內最早 end 為錨，只收與錨相差 ≤ tolerance 者。用單鏈接的話
     * A↔B 差 3 天、B↔C 差 3 天，會把 A↔C 差 6 天的兩個不同年度併成一組。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>>>
     */
    private function groupByEnd(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => strcmp($a['end'], $b['end']));

        $tolerance = (int) config('financial_statements.date_tolerance_days') * 86400;
        $groups = [];
        $current = [];
        $anchor = null;

        foreach ($rows as $row) {
            $end = strtotime($row['end']);

            if ($anchor === null) {
                $anchor = $end;
                $current = [$row];

                continue;
            }

            if ($end - $anchor <= $tolerance) {
                $current[] = $row;

                continue;
            }

            $groups[] = $current;
            $current = [$row];
            $anchor = $end;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * 年度長度中位數，由**無歧義的組**（只有一個候選）算出。
     *
     * 全部組都有歧義時退回用全部候選——那時沒有更好的參考點。
     *
     * @param  list<list<array<string, mixed>>>  $groups
     */
    private function medianLength(array $groups): int
    {
        $lengths = [];

        foreach ($groups as $group) {
            if (count($group) === 1) {
                $lengths[] = $this->lengthOf($group[0]);
            }
        }

        if ($lengths === []) {
            foreach ($groups as $group) {
                foreach ($group as $row) {
                    $lengths[] = $this->lengthOf($row);
                }
            }
        }

        sort($lengths);
        $mid = intdiv(count($lengths), 2);

        return count($lengths) % 2 === 1
            ? $lengths[$mid]
            : (int) round(($lengths[$mid - 1] + $lengths[$mid]) / 2);
    }

    /**
     * 組內決勝：長度最接近中位數者；平手取較長（完整年度優先於過渡期）。
     *
     * **不可用 tag 數量**：實測 RGTI 的 2021-12-31 同時有 364 天（26 個 tag）與
     * 333 天（63 個 tag，SPAC 前身期間）兩個候選，兩者 form 都是 10-K、fy 都帶
     * 2022，用 form／fy／tag 數量都會選錯。
     *
     * @param  list<array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private function pickWinner(array $group, int $median): array
    {
        usort($group, function (array $a, array $b) use ($median): int {
            return [abs($this->lengthOf($a) - $median), -$this->lengthOf($a)]
                <=> [abs($this->lengthOf($b) - $median), -$this->lengthOf($b)];
        });

        return $group[0];
    }

    /**
     * 組內落敗且**起訖日明顯不同**的候選。
     *
     * 與勝出者相差 ≤ tolerance 的是同一期間的 tag 差異，直接丟棄——
     * 把它們落成 stub 會產生大量假過渡期。
     *
     * @param  list<array<string, mixed>>  $group
     * @return list<array<string, mixed>>
     */
    private function losers(array $group, array $winner): array
    {
        $tolerance = (int) config('financial_statements.date_tolerance_days') * 86400;
        $out = [];

        foreach ($group as $row) {
            if ($row['start'] === $winner['start'] && $row['end'] === $winner['end']) {
                continue;
            }

            if (abs(strtotime($row['start']) - strtotime($winner['start'])) <= $tolerance
                && abs(strtotime($row['end']) - strtotime($winner['end'])) <= $tolerance) {
                continue;
            }

            $out[] = $row;
        }

        usort($out, fn (array $a, array $b) => strcmp($a['start'], $b['start']));

        return array_values($out);
    }

    /**
     * 該期間真正的財政年度編號。
     *
     * 兩步：先取最早 filed 那一列的 fy，再套同 accession 校正。
     *
     * 校正這步不可省，既有程式也有等價邏輯
     * （SecEdgarFinancialsProvider::correctFiscalYearByFiling()，:440）：
     * 一份 10-K 會同時申報三個比較年度，三列全部帶著同一個 fy。只取「最早 filed
     * 的 fy」不做校正，三個年度會全部歸到申報當年。
     *
     * @param  list<array<string, mixed>>  $allRows
     */
    private function fiscalYearFor(array $winner, array $allRows): int
    {
        $earliest = $winner;

        foreach ($allRows as $row) {
            if ($row['start'] === $winner['start']
                && $row['end'] === $winner['end']
                && $row['filed'] < $earliest['filed']) {
                $earliest = $row;
            }
        }

        $fy = (int) ($earliest['fy'] ?? 0);
        $accn = $earliest['accn'] ?? null;

        if ($accn === null || $fy === 0) {
            return $fy;
        }

        // 同一 accession 內最晚結束的年度才是該 fy 所指的；其餘依 end 的年份距離回推。
        $latestEnd = $earliest['end'];

        foreach ($allRows as $row) {
            if (($row['accn'] ?? null) === $accn && $row['end'] > $latestEnd) {
                $latestEnd = $row['end'];
            }
        }

        $offset = (int) date('Y', strtotime($latestEnd)) - (int) date('Y', strtotime($earliest['end']));

        return $fy - $offset;
    }

    private function lengthOf(array $row): int
    {
        return (int) round((strtotime($row['end']) - strtotime($row['start'])) / 86400);
    }
}
