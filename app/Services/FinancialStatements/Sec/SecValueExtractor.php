<?php

namespace App\Services\FinancialStatements\Sec;

/**
 * 依已確定的期間邊界，把各科目的值取出來。
 *
 * 期間切分由 SecFiscalCalendar 與 SecQuarterChain 負責，這裡**不做任何期間推斷**，
 * 只回答「這段期間的這個科目是多少」。分開的理由：期間切分錯與取值錯是完全不同
 * 的失效模式，混在一起會讓失敗難以定位。
 */
class SecValueExtractor
{
    /**
     * 期間科目（損益表與現金流量表）。accns 的鍵是 'income' 與 'cashflow'。
     *
     * @param  array<string, mixed>  $facts
     * @return array{values: array<string, ?float>, accns: array<string, ?string>}
     */
    public function forPeriod(array $facts, string $start, string $end): array
    {
        $values = [];
        $accns = ['income' => null, 'cashflow' => null];

        $instant = array_flip((array) config('financial_statements.instant_fields'));
        $cashflow = array_flip((array) config('financial_statements.cashflow_fields'));
        $outflow = array_flip((array) config('financial_statements.outflow_fields'));

        foreach ((array) config('financial_statements.sec_tags') as $field => $tags) {
            if (isset($instant[$field])) {
                continue;
            }

            $hit = $this->firstHit($facts, $tags, 'USD', fn (array $r) => isset($r['start'])
                && $r['start'] === $start && $r['end'] === $end);

            $values[$field] = $hit === null ? null : $this->signed($field, (float) $hit['val'], $outflow);

            if ($hit !== null) {
                $key = isset($cashflow[$field]) ? 'cashflow' : 'income';
                $accns[$key] ??= $hit['accn'] ?? null;
            }
        }

        // EPS 的單位鍵不是 USD 而是 USD/shares。
        foreach ((array) config('financial_statements.sec_eps_tags') as $field => $tags) {
            $hit = $this->firstHit($facts, $tags, 'USD/shares', fn (array $r) => isset($r['start'])
                && $r['start'] === $start && $r['end'] === $end);

            $values[$field] = $hit === null ? null : (float) $hit['val'];
        }

        return ['values' => $values, 'accns' => $accns];
    }

    /**
     * 時點科目（資產負債表）。以 end 配對，容忍 ±3 天，對不上就丟棄。
     *
     * @param  array<string, mixed>  $facts
     * @return array{values: array<string, ?float>, accn: ?string}
     */
    public function forInstant(array $facts, string $end): array
    {
        $tolerance = (int) config('financial_statements.date_tolerance_days') * 86400;
        $target = strtotime($end);

        $values = [];
        $accn = null;

        foreach ((array) config('financial_statements.instant_fields') as $field) {
            $tags = (array) config("financial_statements.sec_tags.{$field}", []);

            $hit = $this->firstHit($facts, $tags, 'USD', fn (array $r) => ! isset($r['start'])
                && isset($r['end'])
                && abs(strtotime($r['end']) - $target) <= $tolerance);

            $values[$field] = $hit === null ? null : (float) $hit['val'];
            $accn ??= $hit['accn'] ?? null;
        }

        return ['values' => $values, 'accn' => $accn];
    }

    /**
     * 依 tag 優先序找**這個期間**的值。
     *
     * 逐 period 判斷，不是「這個 tag 在任何期間出現過就定案」——後者會讓
     * 中途換 tag 的公司在換 tag 之後全部變 null（實測 RGTI 的 Revenues 只到 2023Q1）。
     *
     * 同一期間有多列時取最晚 filed，那是重編後的數字。
     *
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $tags
     * @return array<string, mixed>|null
     */
    private function firstHit(array $facts, array $tags, string $unit, callable $matches): ?array
    {
        foreach ($tags as $tag) {
            $rows = $facts['facts']['us-gaap'][$tag]['units'][$unit] ?? [];
            $best = null;

            foreach ($rows as $row) {
                if (! isset($row['val']) || ! is_numeric($row['val'])) {
                    continue;
                }
                if (! $matches($row)) {
                    continue;
                }
                if ($best === null || ($row['filed'] ?? '') > ($best['filed'] ?? '')) {
                    $best = $row;
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    /**
     * 把流出類科目正規化為負值。
     *
     * SEC 的 PaymentsToAcquirePropertyPlantAndEquipment 是正值（付出的金額），
     * FinMind 的 PropertyAndPlantAndEquipment 是負值（現金流出）。兩個來源的符號
     * 慣例不同，不做正規化的話同一個欄位在台美會是相反的意思。
     *
     * @param  array<string, int>  $outflow
     */
    private function signed(string $field, float $value, array $outflow): float
    {
        if (! isset($outflow[$field])) {
            return $value;
        }

        return $value > 0 ? -$value : $value;
    }
}
