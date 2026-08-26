<?php

namespace App\Enums;

/**
 * 一塊算不出來的成因。**沿用階段 5a 的 {@see RevenueUnknownReason} 五態命名**，
 * 因為使用者要能對照兩處的說法。
 *
 * 五種對使用者是不同的行動：`NotYet` 等一下就有；`NotInUniverse` 與
 * `NotApplicable` 永遠不會有；`Stale` 要等上游更新；`Indeterminate` 是
 * 資料到齊了但這一項本身算不出來。
 */
enum HealthUnavailableReason: string
{
    /** 這個市場沒有這個資料源（例：ROE 只有台股）。永遠不會有。 */
    case NotInUniverse = 'not_in_universe';

    /** 這個產業不適用（例：CCC 對金融、航運）。永遠不會有。 */
    case NotApplicable = 'not_applicable';

    /** 資料還沒累積（例：估值分位需每檔 ≥20 列）。等分析或掃描跑過就會有。 */
    case NotYet = 'not_yet';

    /** 資料有但太舊。等上游更新。 */
    case Stale = 'stale';

    /** 資料到齊但這一項算不出來（例：缺去年同期，年增無從比較）。 */
    case Indeterminate = 'indeterminate';
}
