<?php

namespace App\Data;

/**
 * 一檔標的正規化後的全部期間，舊→新排序。
 */
final readonly class PeriodFactSet
{
    /**
     * @param  list<FinancialPeriod>  $periods  舊→新
     */
    public function __construct(
        public array $periods = [],
        public ?string $market = null,   // 'tw' | 'us'
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->periods === [];
    }
}
