<?php

namespace App\Enums;

/**
 * 社交套利五分類。
 *
 * 涵蓋面只有新聞熱度：SOP 2.3 列的 YouTube、X、Reddit、Threads、PTT、Dcard、
 * 電商通路，本專案一個都沒有接（見 config('order_inventory.social') 的說明），
 * 呈現層必須寫明這件事，不得讓使用者以為涵蓋了社群。
 */
enum SocialArbitrageStage: string
{
    /**
     * 熱度升溫，股價未顯著漲（且跌幅未到 `price_fell`），法人未明顯買
     * （或法人腿不可評估）。
     */
    case Early = 'early';

    /** 熱度升溫，股價已漲（達 `price_risen`），法人開始買（達 `foreign_net_buy_volume_share`，或法人腿不可評估）。 */
    case PartlyPriced = 'partly_priced';

    /**
     * 熱度處於近期歷史高檔，股價**大漲**（達 `price_surged`），法人**已大買**
     * （達 `foreign_net_buy_volume_share_heavy`，或法人腿不可評估）。
     *
     * 股價與籌碼兩腿刻意用比 {@see self::PartlyPriced} 更高的門檻：兩者的差別是
     * 「市場已經反應了多少」，那是價格與籌碼的事；若兩腿共用同一組門檻，唯一的
     * 差別會退化成新聞量，而**新聞熱度高不等於已被反映**。
     */
    case FullyPriced = 'fully_priced';

    /**
     * 熱度升溫，但營收無驗證且毛利率下滑（跌破 `thresholds.gross_margin_stable_pp`
     * 的持平帶）——證據比階段判定更強，排在階段桶之前。
     *
     * 「下滑」沿用階段 2 C2 的同一條持平帶而不是拿 0 當界：QoQ 的四捨五入雜訊
     * 常在 ±0.1pp，用 0 當界會讓大量標的因雜訊被貼上「假訊號」這個強烈負面標籤。
     */
    case FalseSignal = 'false_signal';

    /**
     * 資料不足以判斷階段，或各腿的組合湊不成任何一桶。實際落在這裡的處境有六種，
     * 由 {@see SocialArbitrageInsufficientReason} 區分，呈現層必須讀那個
     * 欄位而不是自行重算——把六種處境全寫成同一句「資料不足」會讓「新聞才 2 則」
     * 與「股價反向大跌」在使用者眼中無從分辨。
     */
    case Insufficient = 'insufficient';
}
