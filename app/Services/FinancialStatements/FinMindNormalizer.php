<?php

namespace App\Services\FinancialStatements;

use App\Data\FinancialPeriod;
use App\Data\PeriodFactSet;
use App\Enums\PeriodType;

/**
 * 台股 FinMind 的正規化。
 *
 * 遠比 SEC 側簡單，因為台股財報是**單季值**而不是 YTD 累計，期別也直接來自
 * date（季末日）——沒有鏈接、沒有 Q4 推導、沒有差分。這個不對稱是資料源的
 * 性質，不是實作偷懶。
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

        foreach ([['income', $income], ['balance', $balance], ['cashflow', $cashflow]] as [$group, $rows]) {
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
            );
        }

        return new PeriodFactSet(array_slice($periods, -max(0, $quarters)), 'tw');
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
