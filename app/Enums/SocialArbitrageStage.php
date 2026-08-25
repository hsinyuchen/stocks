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
    /** 熱度升溫，股價未顯著漲，法人未明顯買（或無法人腿可評估）。 */
    case Early = 'early';

    /** 熱度升溫，股價已漲，法人開始買（或無法人腿可評估）。 */
    case PartlyPriced = 'partly_priced';

    /** 熱度處於近期歷史高檔，股價已漲，法人已大買（或無法人腿可評估）。 */
    case FullyPriced = 'fully_priced';

    /** 熱度升溫，但營收無驗證且毛利率下滑——證據比階段判定更強，排在階段桶之前。 */
    case FalseSignal = 'false_signal';

    /**
     * 資料不足以判斷階段。三種情形都落在這裡：新聞樣本低於門檻、熱度沒有升溫
     * （沒有升溫就沒有「套利階段」可談）、股價漲幅落在灰帶（見 SocialArbitrage::$priceInGreyZone）。
     */
    case Insufficient = 'insufficient';
}
