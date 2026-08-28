<?php

namespace App\Enums;

use App\Contracts\TransmissionRuleProvider;

/**
 * 候選與題材的**關聯強度**，兩層互斥。
 *
 * 刻意**不含營收驗證**：層級講「關聯有多硬」，營收驗證講「有沒有財務佐證」，
 * 是兩個不同的軸。混在一起會讓一檔人工策展過的核心個股，只因為我們還沒抓到
 * 它的財報就被降級成延伸（而 fundamentals 表目前 0 列有序列，那會是常態）。
 *
 * ## 曾經有第三層外圍（`TopicTier::Periphery`，新聞共同提及），已移除
 *
 * 只有兩層看起來像少了一塊，下一個人很自然會想「怎麼沒有新聞層？加一個吧」。
 * 那一層做過、量過、拆掉了，結論留在這裡而不是 git log 裡。
 *
 * 外圍層的定義是「一則新聞同時觸發某題材的傳導鏈規則、且 `related_symbols`
 * 含該標的」，前提是**共同提及代表題材關聯**。實測（生產資料、近 30 日 4941 則
 * relevant 新聞）證明這個前提對**事件型題材不成立**：規格的頭號範例題材
 * `hormuz_oil` 解出來的外圍層是台積電、Microsoft、SpaceX、Meta、Costco、Nvidia、
 * ServiceNow、Micron、Apple、S&P500、Alphabet、Broadcom——沒有一檔與荷莫茲海峽
 * 有關。
 *
 * 根因不是門檻沒調好，是**輸入本身沒有訊號**：觸發該題材的 230 則新聞裡有
 * 182 則（79%）`related_symbols` 是空的（真正談荷莫茲的地緣新聞不提上市公司），
 * 剩下有 symbols 的幾乎全是當日盤勢彙整文。唯一真正談荷莫茲的個股文章抽出來的是
 * ServiceNow——因為標題裡的英文字 `now` 命中了代號 NOW。
 *
 * 三種救法都試過，都救不了：
 *
 * - **排除彙整文**（每則最多 1 檔 symbol）：仍是台積電／MSFT／NOW／SpaceX。
 * - **依「題材新聞談不談公司」設門檻**：分佈 14%–68%，沒有乾淨切點，訂線是武斷門檻。
 * - **提升度 lift**（相對基礎出現率）：`hormuz_oil` 的 AVGO lift 8.73、MSFT 6.79，
 *   高過 `memory_cycle` 裡合理的旺宏 3.35——小樣本讓 lift 虛高。
 *
 * `memory_cycle` 的外圍層（旺宏／美光／南亞科／NVDA）**是對的**——差別在題材的
 * 新聞談公司還是談事件。但八個題材裡約五個是事件型，而我們無法可靠地事先判斷是
 * 哪一種，所以整層拿掉，而不是留著讓使用者自己分辨哪一份清單能看。
 *
 * **什麼條件下值得重做**：新聞抽取能讓真正談該事件的文章帶上正確 symbols 時。
 * 也就是 `related_symbols` 不再只從標題／摘要做代號字串匹配（那條路徑會把
 * `now` 認成 NOW），而是能從文章內容判斷「這篇在談哪些公司」，且地緣、政策這類
 * 不點名上市公司的事件文也能被連到受影響的標的。在那之前，換門檻、換排序、
 * 換統計量都只是在同一份沒有訊號的輸入上換算法。
 */
enum TopicTier: string
{
    /** 在 {@see TransmissionRuleProvider} 回傳的 sectors[].symbols 裡列名。人工策展的映射，最硬。規則維護在資料庫，種子見 {@see \database\seeders\data\transmission_rules.php}。 */
    case Core = 'core';

    /** 與某個核心標的同 industry。僅台股——美股沒有產業別資料。 */
    case Extended = 'extended';
}
