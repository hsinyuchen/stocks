<?php

namespace App\Services\FinancialStatements;

use App\Data\FinancialPeriod;
use App\Data\PeriodFactSet;
use App\Enums\PeriodType;
use App\Models\FinancialStatement;
use App\Models\Instrument;
use Illuminate\Support\Facades\DB;

/**
 * 把正規化後的期間集合落成資料列，並做 window 級 reconciliation。
 *
 * 少了刪除這一步，期間消失或 fiscal label 被更正時舊列會永遠殘留，畫面上會同時
 * 出現新舊兩個版本的同一季。
 *
 * 但刪除範圍**不可**用 fiscal_year 的 min/max：20 季視窗解析出 2021Q2–2026Q1 時，
 * 2021Q1 落在年份區間內卻不在本次產出集合裡，每跑一次就會被吃掉一季，歷史一路
 * 被向前啃；中間某年整年解析失敗時，那一年也會整批被刪。範圍改用精確的槽位序號
 * 區間（季度）與年度集合（年度），stub 跟隨其年度的 annual 判定。
 *
 * 本類別**不開交易**。呼叫端（FetchFinancialStatements）必須把它包在一個同時
 * 持有狀態列 FOR UPDATE 的交易裡——generation 只保護狀態列是不夠的，遲到的
 * worker 會照樣把舊資料寫進來，只是最後那步終態 CAS 失敗，資料早就壞了。
 */
class FinancialStatementWriter
{
    public function write(Instrument $instrument, PeriodFactSet $set, string $source): void
    {
        $now = now();
        $produced = [];

        foreach ($set->periods as $period) {
            $produced[$period->periodType->value][] = $period;
            $this->upsert($instrument, $period, $source, $now);
        }

        $this->reconcile($instrument, $produced);
    }

    private function upsert(Instrument $instrument, FinancialPeriod $period, string $source, \DateTimeInterface $now): void
    {
        $attributes = [
            'instrument_id' => $instrument->id,
            'period_type' => $period->periodType->value,
            'fiscal_year' => $period->fiscalYear,
            'fiscal_quarter' => $period->fiscalQuarter,
        ];

        $row = [
            'period_label' => $period->periodLabel,
            'period_start' => $period->periodStart,
            'period_end' => $period->periodEnd,
            'fiscal_year_complete' => $period->fiscalYearComplete,
            'currency' => $period->currency,
            'source' => $source,
            'income_derivation' => $period->incomeDerivation->value,
            'cashflow_derivation' => $period->cashflowDerivation->value,
            'income_restatement_mixed' => $period->incomeRestatementMixed,
            'cashflow_restatement_mixed' => $period->cashflowRestatementMixed,
            'income_source_accn' => $period->incomeSourceAccn,
            'balance_source_accn' => $period->balanceSourceAccn,
            'cashflow_source_accn' => $period->cashflowSourceAccn,
            'income_fetched_at' => $now,
            'balance_fetched_at' => $now,
            'cashflow_fetched_at' => $now,
        ];

        // 33 個科目一律明確寫入（缺的寫 null）：整張表原子取代，不做欄位級 merge。
        // 欄位級 merge 與表級 provenance 在數學上互斥——保留舊的 research_development
        // 卻更新 income_source_accn，那個 accn 至少對其中一個欄位說謊。
        foreach ($this->fields() as $field) {
            $row[$field] = $period->values[$field] ?? null;
        }

        FinancialStatement::updateOrCreate($attributes, $row);
    }

    /**
     * @param  array<string, list<FinancialPeriod>>  $produced
     */
    private function reconcile(Instrument $instrument, array $produced): void
    {
        $this->reconcileQuarters($instrument, $produced[PeriodType::Quarter->value] ?? []);
        $this->reconcileAnnuals($instrument, $produced[PeriodType::Annual->value] ?? []);
    }

    /**
     * 季度：權威範圍是本次產出的槽位序號連續區間。
     *
     * 只刪落在 [min(slot), max(slot)] 區間內、且不在本次產出槽位集合裡的季度列。
     * 區間外一律不動——這才是防住「20 季視窗往前滾動時啃掉邊界季度」的關鍵：
     * 舊視窗的邊界季度槽位序號比新視窗的 min 還小，天生就落在區間外。
     *
     * @param  list<FinancialPeriod>  $periods
     */
    private function reconcileQuarters(Instrument $instrument, array $periods): void
    {
        if ($periods === []) {
            // 一次解析失敗不該清空使用者看得到的全部歷史。
            return;
        }

        $slots = array_map(static fn (FinancialPeriod $p): int => $p->slot(), $periods);

        FinancialStatement::query()
            ->where('instrument_id', $instrument->id)
            ->where('period_type', PeriodType::Quarter->value)
            ->whereRaw('(fiscal_year * 10 + fiscal_quarter) BETWEEN ? AND ?', [min($slots), max($slots)])
            ->whereNotIn(DB::raw('(fiscal_year * 10 + fiscal_quarter)'), $slots)
            ->delete();
    }

    /**
     * 年度：權威範圍是本次產出的年度**集合**，不是 min/max 區間。
     *
     * annual 對 fiscal_year 是 1:1 對應（fiscal_quarter 恆為 0，唯一鍵不允許同一
     * instrument、同一年度出現第二筆 annual 列）。本次產出的每個年度都已經在上面
     * 的 upsert() 就地更新到位，範圍內不存在「屬於本次產出年度、卻不是本次產出
     * 結果」的殘留列可刪——這正是刻意的設計：中間某年整年解析失敗時，那一年根本
     * 不會出現在集合裡，自然被排除在範圍外，不會被觸碰（用 min/max 區間才會誤刪）。
     *
     * stub 跟隨其年度：該年度不在 annual 集合內時，它的 stub 一律不動——這裡完全
     * 不觸碰 period_type=stub 的列，上述不變式自動成立。
     *
     * 注意：不可比照 reconcileQuarters 用 fiscal_year 的 min/max 區間改寫這裡，
     * 那正是本 task 要防的邊界啃蝕與整年連坐刪除；`test_annual_uses_a_set_not_a_range`
     * 釘住這條規則。
     *
     * @param  list<FinancialPeriod>  $periods
     */
    private function reconcileAnnuals(Instrument $instrument, array $periods): void
    {
        // 沒有刪除語句：annual 的 1:1 年度鍵配上「絕不刪除消失年度」的保守政策，
        // 代表這裡永遠沒有東西可刪（見上方 docblock）。刻意留空，不寫看似在做事、
        // 實際上不論怎麼組排除條件都刪不到列的裝飾性查詢。
    }

    /**
     * 33 個科目欄位。權威來源是 config，不在這裡複製一份會過期的副本。
     *
     * @return list<string>
     */
    private function fields(): array
    {
        return array_merge(
            (array) config('financial_statements.income_fields'),
            array_keys((array) config('financial_statements.sec_eps_tags')),
            (array) config('financial_statements.instant_fields'),
            (array) config('financial_statements.cashflow_fields'),
        );
    }
}
