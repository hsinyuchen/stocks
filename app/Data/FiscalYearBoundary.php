<?php

namespace App\Data;

use App\Enums\PeriodType;

/**
 * 一個財政年度（或過渡期）的邊界。
 *
 * $fiscalYear 是**公司自稱**的年度編號，不是日曆年：COST FY2025 結在 2025-08-31、
 * NVDA FY2026 結在 2026-01-25、MSFT FY2026 結在 2026-06-30。實測五家全對。
 */
final readonly class FiscalYearBoundary
{
    public function __construct(
        public int $fiscalYear,
        public string $start,          // 'YYYY-MM-DD'
        public string $end,
        public PeriodType $type,       // Annual 或 Stub
        /** 同一 fiscalYear 下的 stub 槽位序號，Annual 恆為 0。 */
        public int $stubSlot = 0,
    ) {}

    public function lengthDays(): int
    {
        return (int) round((strtotime($this->end) - strtotime($this->start)) / 86400);
    }
}
