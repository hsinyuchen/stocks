<?php

namespace App\Enums;

/** 中長線的四塊。順序即呈現順序：先看貴不貴，再看賺不賺、成不成長、實不實在。 */
enum HealthBlock: string
{
    case Valuation = 'valuation';
    case ReturnOnEquity = 'return_on_equity';
    case Growth = 'growth';
    case Quality = 'quality';
}
