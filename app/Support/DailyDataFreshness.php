<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 「每日盤後公佈」型資料的新鮮度判斷。
 *
 * 固定小時數的 TTL 對這類資料是錯的模型，實際踩過兩個坑：
 *
 *   1. 過期時刻會漂移。昨天 16:34 抓的，今天就得等到 16:34；明天 17:00 抓，
 *      後天變 17:00。使用者在公佈時間到過期時刻之間，永遠看到前一日的數字。
 *   2. 時區。伺服器跑 UTC，台股 15:00 公佈的資料對應 UTC 07:00；用 24 小時
 *      TTL 時，UTC 16:34 抓的快取要到隔天 UTC 16:34（台北 07-30 00:34）才過期
 *      ——整個交易日都拿不到當天資料。
 *
 * 正確的問法是「今天的資料公佈了沒、我手上這份是不是公佈後抓的」，與距離上次
 * 抓取過了幾小時無關。
 */
final class DailyDataFreshness
{
    /** 台股盤後資料的時區。證交所與 FinMind 的日期都以此為準。 */
    public const TIMEZONE = 'Asia/Taipei';

    /**
     * 快取是否該重抓。
     *
     * @param  CarbonInterface|null  $fetchedAt  上次抓取時間（null 視為沒有資料）
     * @param  int  $publishHour  當地時間的公佈時刻（小時）
     */
    public static function isStale(?CarbonInterface $fetchedAt, int $publishHour): bool
    {
        if ($fetchedAt === null) {
            return true;
        }

        $publishedAt = self::todayPublishedAt($publishHour);

        // 今天還沒到公佈時間：上游沒有新東西，手上這份就是最新的。
        if (CarbonImmutable::now(self::TIMEZONE)->lessThan($publishedAt)) {
            return false;
        }

        // 已過公佈時間：只要這份是公佈前抓的就該更新。比較一律轉同一時區，
        // 否則 UTC 的 fetched_at 與台北的公佈時刻會差 8 小時。
        return CarbonImmutable::parse($fetchedAt)
            ->setTimezone(self::TIMEZONE)
            ->lessThan($publishedAt);
    }

    /** 今天的公佈時刻（當地時間）。 */
    public static function todayPublishedAt(int $publishHour): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)
            ->startOfDay()
            ->addHours(max(0, min(23, $publishHour)));
    }
}
