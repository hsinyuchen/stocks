<?php

namespace App\Enums;

/**
 * 期間類型。
 *
 * Stub 是財政年度變更的過渡期、或 SPAC 併購的前身期間。它不是完整年度也不是季，
 * 丟棄的話「這家公司那一年到底發生什麼」在畫面上會完全消失。
 */
enum PeriodType: string
{
    case Quarter = 'quarter';
    case Annual = 'annual';
    case Stub = 'stub';
}
