<?php

namespace App\Enums;

use App\Data\IndustryMomentum;
use App\Services\Fundamentals\IndustryMomentumSampler;

/**
 * {@see IndustryMomentum::$applicable} 為 false 的原因。
 *
 * 存在的理由是呈現層（prompt 區塊與前端）**不得自行推斷**：只給一個
 * `applicable = false` 會讓「這個市場沒有這個功能」與「這檔的產業別抓不到」
 * 長得一模一樣，呈現層要說得出差別就只能自己重判市場與產業，那份複製品
 * 必然與 {@see IndustryMomentumSampler} 漂移。
 *
 * 這個 enum **不涵蓋「樣本還不夠」**：那種情況 `applicable` 為 true、
 * `samples` 照實回報，語意是「有這個功能，只是還沒累積到樣本」，與不適用
 * 必須分開講。
 */
enum IndustryMomentumUnavailableReason: string
{
    /**
     * 非台股。產業動能定義為「同 industry_category 的月營收 YoY 中位數」，
     * 而美股沒有月營收（SEC 不提供）、industry 也恆為 null（階段 1 決定不抓 SIC）。
     */
    case NotTaiwan = 'not_taiwan';

    /**
     * 台股但產業別未知（快取列沒有 industry，或上游沒給）。產業未知就沒有
     * 「同業」可言，拿全市場當產業比出來的中位數沒有解讀價值。
     */
    case IndustryUnknown = 'industry_unknown';
}
