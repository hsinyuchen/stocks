<?php

namespace App\Enums;

/**
 * 候選與題材的**關聯強度**，三層互斥。
 *
 * 刻意**不含營收驗證**：層級講「關聯有多硬」，營收驗證講「有沒有財務佐證」，
 * 是兩個不同的軸。混在一起會讓一檔人工策展過的核心個股，只因為我們還沒抓到
 * 它的財報就被降級成延伸（而 fundamentals 表目前 0 列有序列，那會是常態）。
 */
enum TopicTier: string
{
    /** 在 config('news.transmission') 的 sectors[].symbols 裡列名。人工策展的映射，最硬。 */
    case Core = 'core';

    /** 與某個核心標的同 industry。僅台股——美股沒有產業別資料。 */
    case Extended = 'extended';

    /** 僅新聞共同提及。不在傳導表內，系統不知道它與題材的因果關係。 */
    case Periphery = 'periphery';
}
