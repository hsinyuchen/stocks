<?php

namespace App\Enums;

/**
 * 一張報表的數值來源。
 *
 * 不能用布林：逐科目推導會讓同一張損益表同時含直接值（有 Q4 原始列的科目）
 * 與推導值（沒有的科目），單一布林會把來源講錯。
 */
enum DerivationKind: string
{
    /** 全部科目都來自申報原始列。 */
    case Direct = 'direct';

    /** 全部有值的科目都由推導或差分而來。 */
    case Derived = 'derived';

    /** 兩者都有。UI 標「本期含推導值」而不是「本期為推導值」。 */
    case Mixed = 'mixed';
}
