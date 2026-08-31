<?php

namespace App\Services\FinancialStatements\Sec;

use App\Enums\DerivationKind;

/**
 * 第四季的推導與重編偵測。
 *
 * 為什麼需要推導：美股 Q1／Q2／Q3 出 10-Q，第四季通常只包含在 10-K 的全年數字裡。
 * 實測 COST／AAPL／MSFT／NVDA／RGTI 的近年財政年度，用起訖日鏈接都只切出 3 季。
 * 直接揭露的 Q4 確實存在（Costco 到 FY2017 為止會在 10-K 揭露 16／17 週的 Q4），
 * 所以規則是「有就用，缺才推導」。
 */
class SecQuarterDeriver
{
    /**
     * 由「全年 − 前三季」逐科目推導損益表的第四季。
     *
     * @param  array<string, ?float>  $annual  全年各科目
     * @param  list<array<string, ?float>>  $quarters  前三季各科目
     * @param  array<string, ?float>  $direct  已有直接值的科目（優先採用）
     * @return array{values: array<string, ?float>, kind: DerivationKind}
     */
    public function deriveIncome(array $annual, array $quarters, array $direct = []): array
    {
        $values = [];
        $hasDirect = false;
        $hasDerived = false;

        foreach ((array) config('financial_statements.income_fields') as $field) {
            if (array_key_exists($field, $direct) && $direct[$field] !== null) {
                $values[$field] = $direct[$field];
                $hasDirect = true;

                continue;
            }

            $values[$field] = $this->subtract($field, $annual, $quarters);

            if ($values[$field] !== null) {
                $hasDerived = true;
            }
        }

        return [
            'values' => $values,
            'kind' => match (true) {
                $hasDirect && $hasDerived => DerivationKind::Mixed,
                $hasDerived => DerivationKind::Derived,
                default => DerivationKind::Direct,
            },
        ];
    }

    /**
     * 逐科目相減。任一筆缺就回 null——**不可用兩季推四季**，少一季會把兩季的量算成一季。
     *
     * @param  array<string, ?float>  $annual
     * @param  list<array<string, ?float>>  $quarters
     */
    private function subtract(string $field, array $annual, array $quarters): ?float
    {
        $total = $annual[$field] ?? null;

        if ($total === null || count($quarters) !== 3) {
            return null;
        }

        $sum = 0.0;

        foreach ($quarters as $q) {
            $v = $q[$field] ?? null;

            if ($v === null) {
                return null;
            }

            $sum += $v;
        }

        return $total - $sum;
    }

    /**
     * 該期間最晚一次「重編」的 filed；未被重編回 null。
     *
     * 判準是**同一科目、同一期間存在多個 accn 且 val 不同**。
     * 必須逐 tag 各自累積 accn→val，不能把所有 us-gaap 科目混進同一個 map 裡
     * 比對——不同 accession（同一份 filing）底下的多個科目本來就有不同數值，
     * 混在一起比對會把不相干的科目誤判成同一件事的兩個版本，也可能讓同 accn
     * 但不同科目的列彼此覆蓋，蓋掉真正的重編訊號。
     * 實測 AAPL FY2008 就踩到後者：revenue 用 SalesRevenueNet，同一份 filing裡
     * 的 ShareBasedCompensation 沒有被重編，若共用一個 map，後者的列會覆蓋掉
     * 前者、抹掉 32,479,000,000 → 37,491,000,000 這次重編。
     *
     * 只是被後續申報重列一次相同數字不算重編——那是比較期，每份 10-K 都會有。
     */
    public function restatedAt(array $facts, string $start, string $end): ?string
    {
        $latestRestatementFiled = null;

        foreach ($this->relevantTags() as $tag) {
            $def = $facts['facts']['us-gaap'][$tag] ?? null;

            if (! is_array($def)) {
                continue;
            }

            $byAccn = [];

            foreach (($def['units'] ?? []) as $unit => $rows) {
                if ($unit !== 'USD') {
                    continue;
                }

                foreach ($rows as $row) {
                    if (($row['start'] ?? null) !== $start || ($row['end'] ?? null) !== $end) {
                        continue;
                    }
                    if (! isset($row['val'], $row['accn'], $row['filed'])) {
                        continue;
                    }

                    $byAccn[$row['accn']] = ['val' => (float) $row['val'], 'filed' => $row['filed']];
                }
            }

            if (count($byAccn) < 2) {
                continue;
            }

            $values = array_column($byAccn, 'val');

            if (count(array_unique($values)) < 2) {
                continue;
            }

            // 該科目的值不同 → 曾被重編。取這個科目最晚那次的 filed。
            $filed = array_column($byAccn, 'filed');
            sort($filed);
            $tagLatest = end($filed);

            if ($latestRestatementFiled === null || $tagLatest > $latestRestatementFiled) {
                $latestRestatementFiled = $tagLatest;
            }
        }

        return $latestRestatementFiled;
    }

    /**
     * 一組來源期間之間是否跨越了同一次重編事件。
     *
     * **這是第三次改寫，兩個錯誤寫法記錄在此避免回頭**：
     *
     * - 「accession 不同就算」——Q1／Q2／Q3 本來就分屬三份 10-Q，恆為 true。
     * - 「本次採用的版本不是最新才算」——策略就是每期取最新，恆為 false。
     *
     * 正確的關鍵是：某個來源被重編了，而**其他來源的最新版本還停在那次重編之前**。
     * 這正是美股最常見的形狀——後續年度的 10-K 追溯重編全年，但極少回頭補發前三季
     * 的 10-Q/A。實測 AAPL 有 6 個年度被重編、季度只有 2 個。
     *
     * @param  list<array{start: string, end: string}>  $periods
     */
    public function isMixed(array $facts, array $periods): bool
    {
        $restatements = [];
        $latestFiled = [];

        foreach ($periods as $i => $p) {
            $restatements[$i] = $this->restatedAt($facts, $p['start'], $p['end']);
            $latestFiled[$i] = $this->latestFiled($facts, $p['start'], $p['end']);
        }

        foreach ($restatements as $i => $at) {
            if ($at === null) {
                continue;
            }

            foreach ($latestFiled as $j => $filed) {
                if ($i === $j || $filed === null) {
                    continue;
                }

                if ($filed < $at) {
                    return true;
                }
            }
        }

        return false;
    }

    private function latestFiled(array $facts, string $start, string $end): ?string
    {
        $latest = null;

        foreach ($this->relevantTags() as $tag) {
            $def = $facts['facts']['us-gaap'][$tag] ?? null;

            if (! is_array($def)) {
                continue;
            }

            foreach (($def['units'] ?? []) as $unit => $rows) {
                if ($unit !== 'USD') {
                    continue;
                }

                foreach ($rows as $row) {
                    if (($row['start'] ?? null) !== $start || ($row['end'] ?? null) !== $end) {
                        continue;
                    }
                    if (! isset($row['filed'])) {
                        continue;
                    }
                    if ($latest === null || $row['filed'] > $latest) {
                        $latest = $row['filed'];
                    }
                }
            }
        }

        return $latest;
    }

    /**
     * 重編偵測要掃描的 tag 集合：只限三表實際用到的科目
     * （sec_tags ∪ sec_eps_tags），不掃 companyfacts 底下的全部 us-gaap tag。
     *
     * 實測用真實未裁切的 AAPL companyfacts（503 個 tag）對照跑過同一支
     * normalizer：裁切後的 fixture（只留這裡限縮的集合 ∪ anchor_tags，46 個）
     * 算出 restatement_mixed=5／90 期，真實完整檔案算出 31／90 期——任何一個
     * 與三表無關的 tag 被重編（例如某個揭露性附註科目）都會讓「這期跨重編」
     * 誤報，而 fixture 因為根本不含那些無關 tag，變異測試也測不出這個問題。
     * 「本層顯示的數字跨越了重編」的旗標語意，只該由三表實際用到的科目決定。
     *
     * docblock 舉的 AAPL FY2008 SalesRevenueNet 例子在 sec_tags 集合內，
     * 限縮後這個既有案例的行為不變（見 test_aapl_fy2008_is_detected_as_restated）。
     *
     * @return list<string>
     */
    private function relevantTags(): array
    {
        $tags = [];

        foreach ((array) config('financial_statements.sec_tags') as $group) {
            $tags = array_merge($tags, (array) $group);
        }

        foreach ((array) config('financial_statements.sec_eps_tags') as $group) {
            $tags = array_merge($tags, (array) $group);
        }

        return array_values(array_unique($tags));
    }
}
