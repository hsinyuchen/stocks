<?php

namespace App\Services\FinancialStatements\Sec;

use App\Data\FiscalYearBoundary;
use App\Enums\DerivationKind;

/**
 * 把美股的 YTD 累計現金流還原成單季值。
 *
 * 實測 RGTI 的營業活動現金流：
 *   2025-01-01~2025-03-31   89天   -13,651,000  10-Q
 *   2025-01-01~2025-06-30  180天   -29,820,000  10-Q
 *   2025-01-01~2025-09-30  272天   -43,642,000  10-Q
 *   2025-01-01~2025-12-31  364天   -58,543,000  10-K
 * 只有 Q1 是真單季。這也解釋了為何現金流的季 frame 特別少——SEC 不會給累計列
 * 季度 frame，只認 frame 的實作會讓現金流大量缺季。
 *
 * 這與損益表的 Q4 推導是**不同的形狀**，規則分開：損益表要四筆齊全，
 * 現金流只需兩筆相鄰的 YTD。
 */
class SecCashFlowDiffer
{
    /**
     * @param  list<array{start: string, end: string}>  $quarters  鏈出的季度
     * @param  int  $index  0 起算；index 3 且 $quarters 只有 3 個時代表推導的第四季
     * @return array{values: array<string, ?float>, kind: DerivationKind}
     */
    public function forQuarter(array $facts, FiscalYearBoundary $year, array $quarters, int $index): array
    {
        $thisEnd = $quarters[$index]['end'] ?? $year->end;
        $prevEnd = $index === 0 ? null : ($quarters[$index - 1]['end'] ?? null);

        $values = [];
        $derived = false;
        $direct = false;

        foreach ((array) config('financial_statements.cashflow_fields') as $field) {
            $current = $this->ytd($facts, $field, $year->start, $thisEnd);

            if ($current === null) {
                $values[$field] = null;

                continue;
            }

            if ($prevEnd === null) {
                // 第一季的 YTD 就是單季值。
                $values[$field] = $current;
                $direct = true;

                continue;
            }

            $previous = $this->ytd($facts, $field, $year->start, $prevEnd);

            // 前一期缺就留 null。**不可跨期相減**：拿 Q3 YTD 減 Q1 YTD 會得到
            // 6 個月的數字，卻被標成單季。
            $values[$field] = $previous === null ? null : $current - $previous;

            if ($values[$field] !== null) {
                $derived = true;
            }
        }

        return [
            'values' => $values,
            'kind' => match (true) {
                $direct && $derived => DerivationKind::Mixed,
                $derived => DerivationKind::Derived,
                default => DerivationKind::Direct,
            },
        ];
    }

    /**
     * 某個 YTD 累計值：start 等於財政年度起點、end 等於指定日（各 ±3 天）。
     *
     * YTD 列不在季度鏈接裡——鏈接只收 70–125 天，而 YTD 是 180／272／364 天。
     * 所以要用已建立的季度邊界回頭找。
     */
    private function ytd(array $facts, string $field, string $fyStart, string $end): ?float
    {
        $tolerance = (int) config('financial_statements.date_tolerance_days') * 86400;
        $outflow = array_flip((array) config('financial_statements.outflow_fields'));

        $startTs = strtotime($fyStart);
        $endTs = strtotime($end);

        foreach ((array) config("financial_statements.sec_tags.{$field}", []) as $tag) {
            $rows = $facts['facts']['us-gaap'][$tag]['units']['USD'] ?? [];
            $best = null;

            foreach ($rows as $row) {
                if (! isset($row['start'], $row['end'], $row['val']) || ! is_numeric($row['val'])) {
                    continue;
                }
                if (abs(strtotime($row['start']) - $startTs) > $tolerance) {
                    continue;
                }
                if (abs(strtotime($row['end']) - $endTs) > $tolerance) {
                    continue;
                }
                if ($best === null || ($row['filed'] ?? '') > ($best['filed'] ?? '')) {
                    $best = $row;
                }
            }

            if ($best !== null) {
                $value = (float) $best['val'];

                return isset($outflow[$field]) && $value > 0 ? -$value : $value;
            }
        }

        return null;
    }
}
