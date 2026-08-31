<?php

namespace App\Services\FinancialStatements\Sec;

use App\Data\FinancialPeriod;
use App\Data\FiscalYearBoundary;
use App\Data\PeriodFactSet;
use App\Enums\DerivationKind;
use App\Enums\PeriodType;

/**
 * 把 companyfacts 正規化成財政序列的期間集合。
 *
 * 這裡只做組裝，每一步的規則都在各自的類別裡。**是純函式**——不打 HTTP、
 * 不讀資料庫，所以可以直接餵真實 fixture 逐期驗值，這正是「用沒有 frame 的列
 * 驗證」得以成立的前提。
 */
class SecNormalizer
{
    public function __construct(
        private readonly SecFiscalCalendar $calendar = new SecFiscalCalendar,
        private readonly SecQuarterChain $chain = new SecQuarterChain,
        private readonly SecValueExtractor $extractor = new SecValueExtractor,
        private readonly SecQuarterDeriver $deriver = new SecQuarterDeriver,
        private readonly SecCashFlowDiffer $differ = new SecCashFlowDiffer,
    ) {}

    public function normalize(array $facts, int $quarters, int $years): PeriodFactSet
    {
        $periods = [];

        foreach ($this->calendar->boundaries($facts) as $year) {
            if ($year->type === PeriodType::Stub) {
                $periods[] = $this->stubPeriod($facts, $year);

                continue;
            }

            $periods = array_merge($periods, $this->annualPeriods($facts, $year));
        }

        // 尚未申報 10-K 的進行中財政年度：只補已鏈出的季度，不推導 Q4、不產出年度列
        // （見 SecFiscalCalendar::inProgress() 的契約說明）。
        $inProgress = $this->calendar->inProgress($facts);

        if ($inProgress !== null) {
            $periods = array_merge($periods, $this->inProgressPeriods($facts, $inProgress));
        }

        usort($periods, fn (FinancialPeriod $a, FinancialPeriod $b) => $a->slot() <=> $b->slot());

        return new PeriodFactSet($this->truncate($periods, $quarters, $years), 'us');
    }

    /**
     * 一個完整財政年度產出的全部列：四季（第四季可能推導）＋年度列。
     *
     * @return list<FinancialPeriod>
     */
    private function annualPeriods(array $facts, FiscalYearBoundary $year): array
    {
        $chained = $this->chain->chain($facts, $year);
        $complete = count($chained) >= 3;

        [$out, $quarterValues] = $this->quarterPeriods($facts, $year, $chained, $complete);

        $annualValues = $this->extractor->forPeriod($facts, $year->start, $year->end);
        $annualInstant = $this->extractor->forInstant($facts, $year->end);

        if (count($chained) === 3 && $complete) {
            $out[] = $this->derivedFourthQuarter($facts, $year, $chained, $quarterValues, $annualValues, $annualInstant);
        }

        $out[] = new FinancialPeriod(
            periodType: PeriodType::Annual,
            fiscalYear: $year->fiscalYear,
            fiscalQuarter: 0,
            periodLabel: 'FY'.$year->fiscalYear,
            periodStart: $year->start,
            periodEnd: $year->end,
            fiscalYearComplete: $complete,
            currency: 'USD',
            values: array_merge($annualValues['values'], $annualInstant['values']),
            incomeSourceAccn: $annualValues['accns']['income'],
            balanceSourceAccn: $annualInstant['accn'],
            cashflowSourceAccn: $annualValues['accns']['cashflow'],
        );

        return $out;
    }

    /**
     * 進行中財政年度的季度列。
     *
     * **不呼叫 derivedFourthQuarter()、不產出 Annual 列**：這個年度的年報還不存在，
     * SecQuarterDeriver::deriveIncome() 需要的 $annual 無從取得，年度列本身也只是
     * 拿推測邊界取值，不是真實申報數字。
     *
     * 這裡刻意不看 count($chained) 是否 >= 3 就放行——AAPL／COST 的進行中年度
     * 恰好都鏈出 3 季（一般完整年度鏈出 3 季就會觸發 Q4 推導的門檻），若只用
     * 「鏈出幾季」判斷會誤觸推導；「這個年度是不是進行中」必須由呼叫端
     * （即這裡）明確排除，而不是讓季數門檻去猜。
     *
     * @return list<FinancialPeriod>
     */
    private function inProgressPeriods(array $facts, FiscalYearBoundary $year): array
    {
        $chained = $this->chain->chain($facts, $year);

        if ($chained === []) {
            return [];
        }

        [$out] = $this->quarterPeriods($facts, $year, $chained, complete: false);

        return $out;
    }

    /**
     * 鏈出的每一季各自取值：損益、資產負債（時點）、現金流（差分）。
     *
     * annualPeriods() 與 inProgressPeriods() 共用——季度本身的取值規則兩者相同，
     * 差異只在於「之後要不要接著推導 Q4、產出年度列」。
     *
     * @param  list<array{start: string, end: string}>  $chained
     * @return array{0: list<FinancialPeriod>, 1: list<array<string, ?float>>}
     */
    private function quarterPeriods(array $facts, FiscalYearBoundary $year, array $chained, bool $complete): array
    {
        $out = [];
        $quarterValues = [];

        foreach ($chained as $i => $q) {
            $period = $this->extractor->forPeriod($facts, $q['start'], $q['end']);
            $instant = $this->extractor->forInstant($facts, $q['end']);
            $cash = $this->differ->forQuarter($facts, $year, $chained, $i);

            $quarterValues[] = $period['values'];

            $out[] = new FinancialPeriod(
                periodType: PeriodType::Quarter,
                fiscalYear: $year->fiscalYear,
                fiscalQuarter: $i + 1,
                periodLabel: $year->fiscalYear.'Q'.($i + 1),
                periodStart: $q['start'],
                periodEnd: $q['end'],
                fiscalYearComplete: $complete,
                currency: 'USD',
                values: array_merge($period['values'], $instant['values'], $cash['values']),
                incomeDerivation: DerivationKind::Direct,
                cashflowDerivation: $cash['kind'],
                incomeRestatementMixed: false,
                cashflowRestatementMixed: $this->cashflowMixed($facts, $year, $chained, $i),
                incomeSourceAccn: $period['accns']['income'],
                balanceSourceAccn: $instant['accn'],
                cashflowSourceAccn: $period['accns']['cashflow'],
            );
        }

        return [$out, $quarterValues];
    }

    /**
     * 推導的第四季。
     *
     * **損益與現金流各自獨立判斷**：現金流一律走 YTD 差分，與損益表有沒有直接
     * Q4 無關。用「步驟 3 鏈出直接 Q4 就跳過整個推導」當條件的話，COST FY2017
     * 以前的現金流第四季會永遠是 null——10-K 幾乎只揭露全年 YTD。
     *
     * @param  list<array{start: string, end: string}>  $chained
     * @param  list<array<string, ?float>>  $quarterValues
     */
    private function derivedFourthQuarter(
        array $facts,
        FiscalYearBoundary $year,
        array $chained,
        array $quarterValues,
        array $annualValues,
        array $annualInstant,
    ): FinancialPeriod {
        $start = date('Y-m-d', strtotime(end($chained)['end']) + 86400);

        $income = $this->deriver->deriveIncome($annualValues['values'], $quarterValues);
        $cash = $this->differ->forQuarter($facts, $year, $chained, 3);

        $sources = array_map(
            fn (array $q) => ['start' => $q['start'], 'end' => $q['end']],
            $chained
        );
        $sources[] = ['start' => $year->start, 'end' => $year->end];

        return new FinancialPeriod(
            periodType: PeriodType::Quarter,
            fiscalYear: $year->fiscalYear,
            fiscalQuarter: 4,
            periodLabel: $year->fiscalYear.'Q4',
            periodStart: $start,
            periodEnd: $year->end,
            fiscalYearComplete: true,
            currency: 'USD',
            // EPS 不推導（每股盈餘不可加減），但 FinancialPeriod::$values 的不變式是
            // 「全欄位預先鋪好、缺的填 null」——EPS 不在 income/instant/cashflow
            // 任何一組裡，這裡要顯式補 null 鍵，否則推導 Q4 的鍵集合會比直接季度
            // 少兩個，消費端直接存取 $values['eps_basic'] 會撞 undefined array key。
            values: array_merge(
                $income['values'],
                $annualInstant['values'],
                $cash['values'],
                array_fill_keys(array_keys((array) config('financial_statements.sec_eps_tags')), null),
            ),
            incomeDerivation: $income['kind'],
            cashflowDerivation: $cash['kind'],
            incomeRestatementMixed: $this->deriver->isMixed($facts, $sources),
            cashflowRestatementMixed: $this->deriver->isMixed($facts, $sources),
            incomeSourceAccn: $annualValues['accns']['income'],
            balanceSourceAccn: $annualInstant['accn'],
            cashflowSourceAccn: $annualValues['accns']['cashflow'],
        );
    }

    /**
     * 現金流差分的兩筆 YTD 是否跨越重編。
     *
     * @param  list<array{start: string, end: string}>  $chained
     */
    private function cashflowMixed(array $facts, FiscalYearBoundary $year, array $chained, int $index): bool
    {
        if ($index === 0) {
            return false;
        }

        return $this->deriver->isMixed($facts, [
            ['start' => $year->start, 'end' => $chained[$index]['end']],
            ['start' => $year->start, 'end' => $chained[$index - 1]['end']],
        ]);
    }

    /**
     * 過渡期只產出一列，**不鏈季也不推導**——它的長度本來就不是一個標準年度。
     */
    private function stubPeriod(array $facts, FiscalYearBoundary $year): FinancialPeriod
    {
        $values = $this->extractor->forPeriod($facts, $year->start, $year->end);
        $instant = $this->extractor->forInstant($facts, $year->end);

        return new FinancialPeriod(
            periodType: PeriodType::Stub,
            fiscalYear: $year->fiscalYear,
            fiscalQuarter: $year->stubSlot,
            periodLabel: $year->fiscalYear.'S'.$year->stubSlot,
            periodStart: $year->start,
            periodEnd: $year->end,
            fiscalYearComplete: false,
            currency: 'USD',
            values: array_merge($values['values'], $instant['values']),
            incomeSourceAccn: $values['accns']['income'],
            balanceSourceAccn: $instant['accn'],
            cashflowSourceAccn: $values['accns']['cashflow'],
        );
    }

    /**
     * 季度取最後 $quarters 個、年度與過渡期取最後 $years 個。
     *
     * 兩者分開截斷：季與年的視窗長度不同，用同一個年份區間去截會讓其中一邊
     * 缺料或超收。進行中年度的季度混在季度清單裡一起排序、一起截斷——它們是
     * 全部季度裡最新的，不需要特殊處理。
     *
     * @param  list<FinancialPeriod>  $periods
     * @return list<FinancialPeriod>
     */
    private function truncate(array $periods, int $quarters, int $years): array
    {
        $q = array_values(array_filter($periods, fn (FinancialPeriod $p) => $p->periodType === PeriodType::Quarter));
        $y = array_values(array_filter($periods, fn (FinancialPeriod $p) => $p->periodType !== PeriodType::Quarter));

        $kept = array_merge(
            array_slice($q, -$quarters),
            array_slice($y, -$years),
        );

        usort($kept, fn (FinancialPeriod $a, FinancialPeriod $b) => $a->slot() <=> $b->slot());

        return array_values($kept);
    }
}
