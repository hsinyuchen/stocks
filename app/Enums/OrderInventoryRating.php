<?php

namespace App\Enums;

/**
 * 訂單／庫存判斷的評級。
 *
 * **刻意沒有 A。** 框架的 A 級要求六個條件，其中「訂單公告、法說會或重大訊息支持」
 * 是非結構化資訊，系統拿不到；台股另缺存貨組成。用一半證據給最高信心等級，
 * 正是框架第 0 節在防的事。要加 A 之前先讀設計文件的「規則永遠給不出 A 級」一節。
 */
enum OrderInventoryRating: string
{
    case BPlus = 'B+';
    case B = 'B';
    case C = 'C';
    case Insufficient = 'insufficient';
    case NotApplicable = 'not_applicable';

    /** 可比較高低的評級刻度；insufficient 與 not_applicable 不在刻度上。 */
    public function scaleValue(): ?int
    {
        return match ($this) {
            self::C => 1,
            self::B => 2,
            self::BPlus => 3,
            default => null,
        };
    }
}
