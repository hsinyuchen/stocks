<?php

namespace App\Services\FinancialStatements\Sec;

use App\Data\FiscalYearBoundary;

/**
 * 在一個財政年度的邊界內，用起訖日鏈出季度骨架。
 *
 * 骨架只由 anchor 清單建立，其餘科目**不參與鏈接**，依已建立的期間邊界去取值
 * （見 SecValueExtractor）。這樣任何一個冷門科目的怪異揭露都不會影響期間切分。
 */
class SecQuarterChain
{
    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{start: string, end: string}> 依 start 舊→新；鏈不出來回 []
     */
    public function chain(array $facts, FiscalYearBoundary $year): array
    {
        $tolerance = (int) config('financial_statements.date_tolerance_days') * 86400;
        $min = (int) config('financial_statements.quarter_days.min');
        $max = (int) config('financial_statements.quarter_days.max');

        $byTag = $this->candidatesByTag($facts, $year, $min, $max);

        $out = [];
        $cursor = strtotime($year->start);
        $fyEnd = strtotime($year->end);
        $nominal = (int) round(($fyEnd - strtotime($year->start)) / 86400 / 4);
        $guard = 0;

        while ($cursor <= $fyEnd && $guard++ < 8) {
            $pick = $this->pickAtCursor($byTag, $cursor, $tolerance, $this->targetLength($out, $nominal));

            if ($pick === null) {
                break;
            }

            $out[] = ['start' => $pick['start'], 'end' => $pick['end']];
            $cursor = strtotime($pick['end']) + 86400;
        }

        return $out;
    }

    /**
     * 依 anchor 優先序整理候選：tag => start => list<row>。
     *
     * 只收長度在窗內的列。下限 70 天是防禦性守衛——同起始日的短天數揭露若被選中，
     * 游標會推進錯誤、後續全部斷鏈。實測五家公司的 21 個 tag 未觀察到這個形狀，
     * 但守衛成本為零。
     *
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function candidatesByTag(array $facts, FiscalYearBoundary $year, int $min, int $max): array
    {
        $out = [];

        foreach ((array) config('financial_statements.anchor_tags') as $tag) {
            $rows = $facts['facts']['us-gaap'][$tag]['units']['USD'] ?? [];

            foreach ($rows as $row) {
                if (! isset($row['start'], $row['end'])) {
                    continue;
                }
                if ($row['start'] < $year->start || $row['end'] > $year->end) {
                    continue;
                }

                $days = (int) round((strtotime($row['end']) - strtotime($row['start'])) / 86400);

                if ($days < $min || $days > $max) {
                    continue;
                }

                $out[$tag][$row['start']][] = $row;
            }
        }

        return $out;
    }

    /**
     * 已鏈出季別的長度中位數；還沒鏈出任何季時用該年度的名目季長。
     *
     * @param  list<array{start: string, end: string}>  $chained
     */
    private function targetLength(array $chained, int $nominal): int
    {
        if ($chained === []) {
            return $nominal;
        }

        $lengths = array_map(
            fn (array $q) => (int) round((strtotime($q['end']) - strtotime($q['start'])) / 86400),
            $chained
        );

        sort($lengths);

        return $lengths[intdiv(count($lengths), 2)];
    }

    /**
     * 在游標位置依三層決勝挑一列。
     *
     * 第一層是 anchor 的 tag 優先序——**逐步進行，不是全年綁定同一個 tag**。
     * 公司在年中改用另一個營收 tag 時，綁定單一 tag 會在該季斷鏈、整年廢棄。
     *
     * @param  array<string, array<string, list<array<string, mixed>>>>  $byTag
     * @return array<string, mixed>|null
     */
    private function pickAtCursor(array $byTag, int $cursor, int $tolerance, int $target): ?array
    {
        foreach ($byTag as $starts) {
            $candidates = [];

            foreach ($starts as $start => $rows) {
                if (abs(strtotime((string) $start) - $cursor) > $tolerance) {
                    continue;
                }

                foreach ($rows as $row) {
                    $candidates[] = $row;
                }
            }

            if ($candidates === []) {
                continue;
            }

            usort($candidates, function (array $a, array $b) use ($target): int {
                $la = (int) round((strtotime($a['end']) - strtotime($a['start'])) / 86400);
                $lb = (int) round((strtotime($b['end']) - strtotime($b['start'])) / 86400);

                // 第二層：最接近目標長度。第三層：filed 最晚 → accn 字典序。
                return [abs($la - $target), $b['filed'] ?? '', $a['accn'] ?? '']
                    <=> [abs($lb - $target), $a['filed'] ?? '', $b['accn'] ?? ''];
            });

            return $candidates[0];
        }

        return null;
    }
}
