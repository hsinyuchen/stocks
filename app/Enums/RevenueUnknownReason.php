<?php

namespace App\Enums;

/**
 * 為什麼訂單庫存框架的 C1（營收驗證）沒有結論。
 *
 * `revenue_verified` 為 null 有五個成因，對使用者是**五種不同的行動**，
 * 而不是同一句「無資料」：
 *
 * - {@see self::NotYet}／{@see self::Indeterminate}：等分析或掃描跑過就可能有答案。
 * - {@see self::Stale}：序列已經完整落地，只是財報的季末日太舊（或缺關鍵科目）。
 *   再跑一百次掃描，季末日也不會往前走——要等下一次財報。
 * - {@see self::NotInUniverse}：這一檔還不在 `instruments` 表裡。建立標的是 ingest
 *   與搜尋的職責，沒有任何分析或掃描會去碰它。實測傳導表 30 檔中有 10 檔如此。
 * - {@see self::NotApplicable}：`config('order_inventory.industry.not_applicable')`
 *   列的服務業（金融保險、證券、銀行、航運、觀光餐旅）不具備一般進銷存循環，
 *   **永遠不會有答案**。
 *
 * 把「永遠不會有」與「等一下就有」講成同一件事，使用者會一直等一個不會來的東西。
 * 這不是假想需求：`config('news.transmission')` 的 hormuz_oil 核心是航運股，
 * 而它的九檔核心裡有六檔根本不在 `instruments` 表。
 *
 * **值為 null 代表 C1 有結論。** 有結論就沒有原因，兩者不會同時有值——同時給
 * 等於讓呈現層拿到互相矛盾的兩個欄位。
 */
enum RevenueUnknownReason: string
{
    /** 標的不在 `instruments` 表：連產業都還不知道。 */
    case NotInUniverse = 'not_in_universe';

    /** 序列尚未落地。 */
    case NotYet = 'not_yet';

    /** 序列完整落地，但季末日超過 `max_quarter_age_days` 或缺關鍵科目。 */
    case Stale = 'stale';

    /** 可評級，但 C1 本身算不出來（既無月營收、序列裡也沒有去年同季）。 */
    case Indeterminate = 'indeterminate';

    /** 本框架不適用這個產業。 */
    case NotApplicable = 'not_applicable';
}
