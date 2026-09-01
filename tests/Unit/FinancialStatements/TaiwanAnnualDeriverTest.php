<?php

namespace Tests\Unit\FinancialStatements;

use App\Data\FinancialPeriod;
use App\Data\PeriodFactSet;
use App\Enums\DerivationKind;
use App\Enums\PeriodType;
use App\Services\FinancialStatements\TaiwanAnnualDeriver;
use Tests\TestCase;

class TaiwanAnnualDeriverTest extends TestCase
{
    /**
     * @param  array<string, ?float>  $values
     */
    private function quarter(int $year, int $q, array $values, string $start = '', string $end = ''): FinancialPeriod
    {
        $starts = [1 => '-01-01', 2 => '-04-01', 3 => '-07-01', 4 => '-10-01'];
        $ends = [1 => '-03-31', 2 => '-06-30', 3 => '-09-30', 4 => '-12-31'];

        return new FinancialPeriod(
            periodType: PeriodType::Quarter,
            fiscalYear: $year,
            fiscalQuarter: $q,
            periodLabel: $year.'Q'.$q,
            periodStart: $start !== '' ? $start : $year.$starts[$q],
            periodEnd: $end !== '' ? $end : $year.$ends[$q],
            fiscalYearComplete: true,
            currency: 'TWD',
            values: $values,
        );
    }

    private function derive(array $quarters): PeriodFactSet
    {
        return (new TaiwanAnnualDeriver)->derive(new PeriodFactSet($quarters, 'tw'));
    }

    private function annual(PeriodFactSet $set, int $year): ?FinancialPeriod
    {
        foreach ($set->periods as $period) {
            if ($period->periodType === PeriodType::Annual && $period->fiscalYear === $year) {
                return $period;
            }
        }

        return null;
    }

    public function test_flow_fields_are_summed_across_four_quarters(): void
    {
        // 台積電 2024 的真實單季營收：相加等於全年 2,894,307,699,000。
        $set = $this->derive([
            $this->quarter(2024, 1, ['revenue' => 592644201000.0, 'operating_cash_flow' => 436311108000.0]),
            $this->quarter(2024, 2, ['revenue' => 673510177000.0, 'operating_cash_flow' => 377668210000.0]),
            $this->quarter(2024, 3, ['revenue' => 759692143000.0, 'operating_cash_flow' => 391992467000.0]),
            $this->quarter(2024, 4, ['revenue' => 868461178000.0, 'operating_cash_flow' => 620205283000.0]),
        ]);

        $annual = $this->annual($set, 2024);

        $this->assertNotNull($annual);
        $this->assertSame(2894307699000.0, $annual->values['revenue']);
        $this->assertSame(1826177068000.0, $annual->values['operating_cash_flow']);
        $this->assertSame('FY2024', $annual->periodLabel);
        $this->assertSame(0, $annual->fiscalQuarter);
        $this->assertSame('2024-01-01', $annual->periodStart);
        $this->assertSame('2024-12-31', $annual->periodEnd);
    }

    public function test_instant_fields_take_the_fourth_quarter_not_the_sum(): void
    {
        // 資產負債表是時點快照。四季相加會得到一個四倍大的假總資產。
        $set = $this->derive([
            $this->quarter(2024, 1, ['total_assets' => 100.0]),
            $this->quarter(2024, 2, ['total_assets' => 110.0]),
            $this->quarter(2024, 3, ['total_assets' => 120.0]),
            $this->quarter(2024, 4, ['total_assets' => 130.0]),
        ]);

        $this->assertSame(130.0, $this->annual($set, 2024)->values['total_assets']);
    }

    public function test_eps_is_never_summed(): void
    {
        // 每股盈餘不可加減：期間內股數會變，四季相加不等於全年 EPS。
        $set = $this->derive([
            $this->quarter(2024, 1, ['eps_basic' => 1.0, 'eps_diluted' => 1.0]),
            $this->quarter(2024, 2, ['eps_basic' => 2.0, 'eps_diluted' => 2.0]),
            $this->quarter(2024, 3, ['eps_basic' => 3.0, 'eps_diluted' => 3.0]),
            $this->quarter(2024, 4, ['eps_basic' => 4.0, 'eps_diluted' => 4.0]),
        ]);

        $annual = $this->annual($set, 2024);

        $this->assertArrayHasKey('eps_basic', $annual->values, '鍵要在，值才是 null');
        $this->assertNull($annual->values['eps_basic']);
        $this->assertNull($annual->values['eps_diluted']);
    }

    public function test_a_null_in_any_quarter_nulls_the_whole_flow_field(): void
    {
        // 用三季的和冒充全年是編造數字。
        $set = $this->derive([
            $this->quarter(2024, 1, ['revenue' => 100.0]),
            $this->quarter(2024, 2, ['revenue' => null]),
            $this->quarter(2024, 3, ['revenue' => 300.0]),
            $this->quarter(2024, 4, ['revenue' => 400.0]),
        ]);

        $this->assertNull($this->annual($set, 2024)->values['revenue']);
    }

    public function test_no_annual_row_without_all_four_quarters(): void
    {
        $set = $this->derive([
            $this->quarter(2024, 1, ['revenue' => 100.0]),
            $this->quarter(2024, 2, ['revenue' => 200.0]),
            $this->quarter(2024, 3, ['revenue' => 300.0]),
        ]);

        $this->assertNull($this->annual($set, 2024), '三季不得產出年度列');
    }

    public function test_derived_annual_is_marked_derived(): void
    {
        $set = $this->derive([
            $this->quarter(2024, 1, ['revenue' => 100.0]),
            $this->quarter(2024, 2, ['revenue' => 200.0]),
            $this->quarter(2024, 3, ['revenue' => 300.0]),
            $this->quarter(2024, 4, ['revenue' => 400.0]),
        ]);

        $annual = $this->annual($set, 2024);

        $this->assertSame(DerivationKind::Derived, $annual->incomeDerivation);
        $this->assertSame(DerivationKind::Derived, $annual->cashflowDerivation);
        $this->assertNull($annual->incomeSourceAccn, '年度列不對應任何單一申報');
    }

    public function test_us_market_is_returned_untouched(): void
    {
        // 美股的年度列來自 10-K，是實據，不可被推導值覆蓋。
        $quarters = [
            $this->quarter(2024, 1, ['revenue' => 100.0]),
            $this->quarter(2024, 2, ['revenue' => 200.0]),
            $this->quarter(2024, 3, ['revenue' => 300.0]),
            $this->quarter(2024, 4, ['revenue' => 400.0]),
        ];

        $set = (new TaiwanAnnualDeriver)->derive(new PeriodFactSet($quarters, 'us'));

        $this->assertCount(4, $set->periods, '美股不得被加上推導年度列');
    }

    public function test_result_stays_sorted_by_slot(): void
    {
        $set = $this->derive([
            $this->quarter(2023, 1, ['revenue' => 1.0]),
            $this->quarter(2023, 2, ['revenue' => 1.0]),
            $this->quarter(2023, 3, ['revenue' => 1.0]),
            $this->quarter(2023, 4, ['revenue' => 1.0]),
            $this->quarter(2024, 1, ['revenue' => 1.0]),
            $this->quarter(2024, 2, ['revenue' => 1.0]),
            $this->quarter(2024, 3, ['revenue' => 1.0]),
            $this->quarter(2024, 4, ['revenue' => 1.0]),
        ]);

        $slots = array_map(static fn ($p) => $p->slot(), $set->periods);
        $sorted = $slots;
        sort($sorted);

        $this->assertSame($sorted, $slots);
        // annual 的 fiscalQuarter = 0，所以它排在同年度所有季度之前。
        $this->assertSame(PeriodType::Annual, $set->periods[0]->periodType);
    }
}
