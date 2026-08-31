<?php

namespace App\Services\FinancialStatements;

use App\Data\FinancialPeriod;
use App\Data\PeriodFactSet;
use App\Enums\DerivationKind;
use App\Enums\PeriodType;

/**
 * 台股 FinMind 的正規化。
 *
 * 損益表與資產負債表比 SEC 側簡單：台股這兩張表是**單季值**，期別也直接來自
 * date（季末日）——沒有鏈接、沒有 Q4 推導。
 *
 * **現金流量表是例外**：台灣 IFRS 季報的現金流量表只揭露「1 月 1 日至本期末」
 * 的累計數（YTD），FinMind 原樣回傳，性質與損益表相反。實測 2330 台積電
 * CashFlowsFromOperatingActivities：2024Q2=813.98bn、2024Q3=1205.97bn、
 * 2024Q4=1826.18bn（皆為全年累計，Q4 那筆其實是全年數字）；若直接當單季值
 * 存，2024Q4 真值約 620.2bn，誤差約 3 倍。所以現金流欄位需要在同一財政年度
 * 內逐季差分（見 diffCashflow()），與美股 SecCashFlowDiffer 的規則對齊：
 * Q1 的 YTD 就是單季值，Q2~Q4 用「本期 YTD − 前一季 YTD」，前期缺就留 null，
 * 且不可跨財政年度相減。
 *
 * 純函式，不打 HTTP、不讀資料庫。
 */
class FinMindNormalizer
{
    /**
     * @param  list<array<string, mixed>>  $income
     * @param  list<array<string, mixed>>  $balance
     * @param  list<array<string, mixed>>  $cashflow
     */
    public function normalize(array $income, array $balance, array $cashflow, int $quarters, int $years): PeriodFactSet
    {
        $fields = $this->allFields();
        $byDate = [];

        // 損益表、資產負債表本來就是單季值／時點值，逐列直接寫入即可。
        foreach ([['income', $income], ['balance', $balance]] as [$group, $rows]) {
            $map = (array) config("financial_statements.finmind_types.{$group}", []);

            foreach ($rows as $row) {
                $date = (string) ($row['date'] ?? '');
                $type = (string) ($row['type'] ?? '');
                $value = $row['value'] ?? null;

                if ($date === '' || $type === '' || ! is_numeric($value)) {
                    continue;
                }

                // 資產負債表混有 _per 佔比列。不濾掉會把百分比當成金額。
                // 這道檢查獨立於下面的欄位對照表：即使設定檔哪天誤把某個 _per
                // 結尾的型別對照到欄位，這裡仍然要擋下來（見
                // FinMindNormalizerTest::test_per_suffix_is_dropped_even_if_it_would_otherwise_map）。
                if (str_ends_with($type, '_per')) {
                    continue;
                }

                $field = array_search($type, $map, true);

                if ($field === false || ! in_array($field, $fields, true)) {
                    continue;
                }

                // 缺的科目留 null——0 是合法的財報數字，不可用來表示「無資料」。
                // 用完整欄位集合預先鋪好每個期間，直接存取消費端就不會遇到
                // undefined array key（例如制度性不揭露的科目）。
                $byDate[$date] ??= array_fill_keys($fields, null);

                $byDate[$date][$field] = $this->signed((string) $field, (float) $value);
            }
        }

        // 現金流量表是 YTD 累計，必須先差分成單季值才能寫入——理由見類別 docblock。
        $cashflowDiff = $this->diffCashflow($cashflow, $fields);

        foreach ($cashflowDiff['values'] as $date => $values) {
            $byDate[$date] ??= array_fill_keys($fields, null);

            foreach ($values as $field => $value) {
                // signed() 必須在差分之後套用：先簽名再差分會把差值的符號再翻一次
                // （見 FinMindNormalizerTest::test_capex_sign_normalization_applies_after_differencing_not_before）。
                $byDate[$date][$field] = $value === null ? null : $this->signed((string) $field, $value);
            }
        }

        ksort($byDate);

        $periods = [];

        foreach ($byDate as $date => $values) {
            $quarter = $this->quarterOf((string) $date);

            $periods[] = new FinancialPeriod(
                periodType: PeriodType::Quarter,
                fiscalYear: (int) substr((string) $date, 0, 4),
                fiscalQuarter: $quarter,
                periodLabel: substr((string) $date, 0, 4).'Q'.$quarter,
                periodStart: $this->quarterStart((string) $date),
                periodEnd: (string) $date,
                fiscalYearComplete: true,
                currency: 'TWD',
                values: $values,
                cashflowDerivation: $cashflowDiff['kind'][$date] ?? DerivationKind::Direct,
            );
        }

        return new PeriodFactSet(array_slice($periods, -max(0, $quarters)), 'tw');
    }

    /**
     * 把現金流量表的 YTD 累計差分成單季值。
     *
     * 依「財政年度→季度序號」而非「處理順序」建索引：若只記錄「上一筆處理過
     * 的列」，遇到中間缺一季（例如只有 Q1、Q3，沒有 Q2）時會誤把 Q3 減 Q1，
     * 得到半年的量卻標成單季——這正是 SecCashFlowDiffer 明確警告過的錯誤。
     * 用季度序號索引可以精確判斷「前一季」是否真的存在。
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields  正規化後的完整欄位集合，只取其中屬於現金流的部分
     * @return array{values: array<string, array<string, ?float>>, kind: array<string, DerivationKind>}
     */
    private function diffCashflow(array $rows, array $fields): array
    {
        $map = (array) config('financial_statements.finmind_types.cashflow', []);
        $cashflowFields = array_values(array_intersect($fields, array_keys($map)));

        // 先按「財政年度 → 季度序號」收原始未簽名 YTD，才能精確找到「前一季」。
        $byYearQuarter = [];

        foreach ($rows as $row) {
            $date = (string) ($row['date'] ?? '');
            $type = (string) ($row['type'] ?? '');
            $value = $row['value'] ?? null;

            if ($date === '' || $type === '' || ! is_numeric($value) || str_ends_with($type, '_per')) {
                continue;
            }

            $field = array_search($type, $map, true);

            if ($field === false || ! in_array($field, $cashflowFields, true)) {
                continue;
            }

            $year = (int) substr($date, 0, 4);
            $quarter = $this->quarterOf($date);

            $byYearQuarter[$year][$quarter]['date'] = $date;
            $byYearQuarter[$year][$quarter]['ytd'][$field] = (float) $value;
        }

        $values = [];
        $kind = [];

        foreach ($byYearQuarter as $quarters) {
            ksort($quarters);

            foreach ($quarters as $quarter => $entry) {
                $date = $entry['date'];
                $ytd = $entry['ytd'];
                // 只認同一年度、序號正好小 1 的那一季，不往更早的季度回溯尋找。
                $previous = $quarter > 1 ? ($quarters[$quarter - 1]['ytd'] ?? null) : null;

                $rowValues = array_fill_keys($cashflowFields, null);
                $direct = false;
                $derived = false;

                foreach ($cashflowFields as $field) {
                    $current = $ytd[$field] ?? null;

                    if ($current === null) {
                        continue;
                    }

                    if ($quarter === 1) {
                        // Q1 的 YTD 本身就是單季值，直接採用。
                        $rowValues[$field] = $current;
                        $direct = true;

                        continue;
                    }

                    $prevValue = $previous[$field] ?? null;

                    if ($prevValue === null) {
                        // 前一季缺這個科目：留 null，不可跨期硬湊出一個誤導性數字。
                        continue;
                    }

                    $rowValues[$field] = $current - $prevValue;
                    $derived = true;
                }

                $values[$date] = $rowValues;
                $kind[$date] = match (true) {
                    $direct && $derived => DerivationKind::Mixed,
                    $derived => DerivationKind::Derived,
                    default => DerivationKind::Direct,
                };
            }
        }

        return ['values' => $values, 'kind' => $kind];
    }

    /**
     * 台美共用同一套版面：損益、資產負債、現金流、EPS 全部欄位的聯集。
     * 台股拿不到的科目（研發費用、SG&A 等）留在集合裡但值恆為 null。
     *
     * @return list<string>
     */
    private function allFields(): array
    {
        return array_values(array_unique(array_merge(
            (array) config('financial_statements.income_fields'),
            (array) config('financial_statements.instant_fields'),
            (array) config('financial_statements.cashflow_fields'),
            array_keys((array) config('financial_statements.sec_eps_tags')),
        )));
    }

    /**
     * 台股的財政年度一律等於日曆年，季末日固定，起始日可以直接推。
     */
    private function quarterStart(string $end): string
    {
        $q = $this->quarterOf($end);

        return substr($end, 0, 4).'-'.str_pad((string) (($q - 1) * 3 + 1), 2, '0', STR_PAD_LEFT).'-01';
    }

    private function quarterOf(string $date): int
    {
        return (int) ceil(((int) substr($date, 5, 2)) / 3);
    }

    /**
     * capex 正規化為負值代表流出。
     *
     * FinMind 的 PropertyAndPlantAndEquipment 原值就是負的（現金流出），
     * SEC 的對應科目卻是正值。不做正規化的話同一個欄位在台美是相反的意思。
     */
    private function signed(string $field, float $value): float
    {
        $outflow = array_flip((array) config('financial_statements.outflow_fields'));

        if (! isset($outflow[$field])) {
            return $value;
        }

        return $value > 0 ? -$value : $value;
    }
}
