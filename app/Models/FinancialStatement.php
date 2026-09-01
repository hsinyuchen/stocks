<?php

namespace App\Models;

use App\Enums\DerivationKind;
use App\Enums\PeriodType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * financial_statements 的一列。
 *
 * 33 個科目欄位刻意用 $guarded = ['id'] 而不是逐一列進 $fillable：欄位清單的
 * 權威來源是 config('financial_statements')，在這裡複製一份只會多一個會過期的
 * 副本。寫入一律經 FinancialStatementWriter，不從請求直接灌。
 */
class FinancialStatement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_type' => PeriodType::class,
            'income_derivation' => DerivationKind::class,
            'cashflow_derivation' => DerivationKind::class,
            'fiscal_year_complete' => 'boolean',
            'income_restatement_mixed' => 'boolean',
            'cashflow_restatement_mixed' => 'boolean',
            'period_start' => 'date',
            'period_end' => 'date',
            'income_fetched_at' => 'datetime',
            'balance_fetched_at' => 'datetime',
            'cashflow_fetched_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    /** 排序與 reconciliation 範圍判定用的槽位序號。 */
    public function slot(): int
    {
        return $this->fiscal_year * 10 + $this->fiscal_quarter;
    }

    /**
     * 槽位的語意不變式。
     *
     * 與 migration 裡的 CHECK 約束是同一條規則的兩個實作：CHECK 只在 MySQL 生效
     * （sqlite 不支援 ALTER TABLE ADD CONSTRAINT），而測試環境是 sqlite。兩邊都要
     * 有，否則這條規則在測試裡完全沒人守。
     */
    public static function slotIsValid(PeriodType $type, int $fiscalQuarter): bool
    {
        return match ($type) {
            PeriodType::Quarter => $fiscalQuarter >= 1 && $fiscalQuarter <= 4,
            PeriodType::Annual => $fiscalQuarter === 0,
            PeriodType::Stub => $fiscalQuarter >= 1,
        };
    }
}
