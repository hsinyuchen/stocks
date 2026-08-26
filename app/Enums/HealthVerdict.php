<?php

namespace App\Enums;

use App\Data\HealthBlockResult;

/**
 * 單一塊的判定。**三態，`Neutral` 不可省。**
 *
 * 「沒達到正面門檻」不等於「負面證據」。把前者講成後者，是本框架五個階段的
 * 審查反覆抓到的同一類錯——系統只知道「這個數字不夠好」，卻對使用者宣稱
 * 「這是壞的」。
 *
 * 「算不出來」**不在這個 enum 裡**：那由 {@see HealthBlockResult}
 * 的 `verdict === null` 加上 `unavailableReason` 表示。混進來會讓
 * 「中性」與「不知道」變成同一個值，而那兩件事對使用者是不同的行動。
 */
enum HealthVerdict: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
}
