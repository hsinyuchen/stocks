<?php

namespace App\Data;

use App\Enums\DerivationKind;
use App\Enums\PeriodType;

/**
 * 一個期間的完整財報結果，對應 financial_statements 的一列。
 *
 * $values 的鍵是 config('financial_statements.income_fields') 等清單裡的欄位名，
 * **不是** SEC tag 名。缺的科目留 null——0 是合法的財報數字，不可用來表示「無資料」。
 */
final readonly class FinancialPeriod
{
    /**
     * @param  array<string, ?float>  $values
     */
    public function __construct(
        public PeriodType $periodType,
        public int $fiscalYear,
        /** quarter 為 1..4；annual 為 0；stub 為槽位序號 1..n。 */
        public int $fiscalQuarter,
        public string $periodLabel,     // '2026Q2' / 'FY2026' / '2021S1'
        public string $periodStart,
        public string $periodEnd,
        /** 該財政年度是否鏈出完整季數。false 時 Q4 一律不推導。 */
        public bool $fiscalYearComplete,
        public string $currency,
        public array $values = [],
        public DerivationKind $incomeDerivation = DerivationKind::Direct,
        public DerivationKind $cashflowDerivation = DerivationKind::Direct,
        public bool $incomeRestatementMixed = false,
        public bool $cashflowRestatementMixed = false,
        /**
         * 該表的 anchor／推導基準 accession。
         *
         * **不宣稱涵蓋該表全部欄位**：逐 period 的 tag fallback 會讓同一張表的不同
         * 欄位來自不同 accession。要精確到欄位需要 per-field provenance，不在範圍內。
         */
        public ?string $incomeSourceAccn = null,
        public ?string $balanceSourceAccn = null,
        public ?string $cashflowSourceAccn = null,
    ) {}

    /** 排序用的槽位序號。annual 的 0 讓它排在同年度所有季度之前。 */
    public function slot(): int
    {
        return $this->fiscalYear * 10 + $this->fiscalQuarter;
    }
}
