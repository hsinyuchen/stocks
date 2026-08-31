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
