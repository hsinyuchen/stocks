<?php

namespace App\Enums;

use App\Data\SocialArbitrage;

/**
 * {@see SocialArbitrageStage::Insufficient} 的細分原因。
 *
 * 存在的理由是呈現層（prompt 區塊與前端）**不得自行重算判定**：只給一個
 * `insufficient` 會讓「新聞才 2 則」「熱度沒升溫」「股價算不出來」「股價落在灰帶」
 * 「股價反向大跌」「腿的組合湊不成任何一桶」六種完全不同的處境長得一模一樣，
 * 呈現層要說得出差別就只能重讀 config 門檻再算一次，那份複製品必然與
 * {@see SocialArbitrageClassifier} 漂移。
 *
 * 只在 `stage === Insufficient` 時為非 null，見 {@see SocialArbitrage::$insufficientReason}。
 */
enum SocialArbitrageInsufficientReason: string
{
    /** 新期新聞則數低於 `min_recent_mentions`，熱度變化率在這種基數上不可信。 */
    case NotEnoughSamples = 'not_enough_samples';

    /**
     * 熱度沒有升溫（且熱度也不在高檔，否則會走 FullyPriced）。沒有升溫就沒有
     * 「套利階段」可談。
     */
    case HeatNotRising = 'heat_not_rising';

    /** 同視窗股價漲幅算不出來（缺行情資料），三個階段桶的股價腿全部無法評估。 */
    case PriceUnavailable = 'price_unavailable';

    /** 股價漲幅落在 `price_flat` 與 `price_risen` 之間的灰帶，兩邊都不歸。 */
    case PriceInGreyZone = 'price_in_grey_zone';

    /**
     * 股價跌幅超過 `price_fell`。字面上「股價未顯著漲」成立，但那是**反向反映**
     * 而不是「尚未反映」，貼「早期」等於宣稱機會還在。
     */
    case PriceFell = 'price_fell';

    /**
     * 熱度升溫、股價腿也算得出來，但各腿的組合湊不成任何一桶——例如台股股價已漲
     * 而外資沒買（不成 PartlyPriced），或股價未漲而外資已在買（不成 Early）。
     */
    case NoBucketMatched = 'no_bucket_matched';
}
