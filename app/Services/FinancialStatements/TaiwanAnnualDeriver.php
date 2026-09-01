<?php

namespace App\Services\FinancialStatements;

use App\Data\FinancialPeriod;
use App\Data\PeriodFactSet;
use App\Enums\DerivationKind;
use App\Enums\PeriodType;

/**
 * 台股的年度列由四季推導。
 *
 * FinMind 的三個財報 dataset 只給季度資料，台股完全沒有年度列，子專案 3 的
 * 「年」分頁會對所有台股都是空的。美股不同：它的年度列來自 10-K，是實據，
 * 這裡原樣放行、絕不覆蓋。
 *
 * 相加之所以正確，前提是季度已經是單季值——台股現金流量表原始是年初至今累計，
 * 這件事在 FinMindNormalizer 差分掉了（見該類別 docblock）。若哪天那層改回
 * 不差分，這裡的加總會是累計值再相加，錯得更離譜。
 */
class TaiwanAnnualDeriver
{
    public function derive(PeriodFactSet $set): PeriodFactSet
    {
        if ($set->market !== 'tw') {
            return $set;
        }

        $byYear = [];

        foreach ($set->periods as $period) {
            if ($period->periodType === PeriodType::Quarter) {
                $byYear[$period->fiscalYear][$period->fiscalQuarter] = $period;
            }
        }

        $periods = $set->periods;

        foreach ($byYear as $year => $quarters) {
            // 三季就加總會把不完整的年度當全年呈現，比沒有更糟。
            if (count(array_intersect_key($quarters, array_flip([1, 2, 3, 4]))) !== 4) {
                continue;
            }

            $periods[] = $this->annualFor((int) $year, $quarters);
        }

        usort($periods, static fn (FinancialPeriod $a, FinancialPeriod $b): int => $a->slot() <=> $b->slot());

        return new PeriodFactSet($periods, $set->market);
    }

    /**
     * @param  array<int, FinancialPeriod>  $quarters  1..4
     */
    private function annualFor(int $year, array $quarters): FinancialPeriod
    {
        $flowFields = array_merge(
            (array) config('financial_statements.income_fields'),
            (array) config('financial_statements.cashflow_fields'),
        );
        $instantFields = (array) config('financial_statements.instant_fields');
        $epsFields = array_keys((array) config('financial_statements.sec_eps_tags'));

        $values = [];

        foreach ($flowFields as $field) {
            $values[$field] = $this->sum($quarters, $field);
        }

        foreach ($instantFields as $field) {
            // 資產負債表是時點快照，年末餘額就是 Q4 的餘額，相加會得到四倍大的假值。
            $values[$field] = $quarters[4]->values[$field] ?? null;
        }

        foreach ($epsFields as $field) {
            // 每股盈餘不可加減：期間內股數會變。鍵要在（消費端逐欄位存取），值留 null。
            $values[$field] = null;
        }

        return new FinancialPeriod(
            periodType: PeriodType::Annual,
            fiscalYear: $year,
            fiscalQuarter: 0,
            periodLabel: 'FY'.$year,
            periodStart: $quarters[1]->periodStart,
            periodEnd: $quarters[4]->periodEnd,
            fiscalYearComplete: true,
            currency: $quarters[4]->currency,
            values: $values,
            incomeDerivation: DerivationKind::Derived,
            cashflowDerivation: DerivationKind::Derived,
        );
    }

    /**
     * @param  array<int, FinancialPeriod>  $quarters
     */
    private function sum(array $quarters, string $field): ?float
    {
        $total = 0.0;

        foreach ([1, 2, 3, 4] as $q) {
            $value = $quarters[$q]->values[$field] ?? null;

            // 缺一季就整欄 null：用三季的和冒充全年是編造數字。
            if ($value === null) {
                return null;
            }

            $total += $value;
        }

        return $total;
    }
}
