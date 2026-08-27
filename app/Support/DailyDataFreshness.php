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

    /**
     * 一個資料日距今幾個交易日。null 代表沒有資料日，**不是 0**。
     *
     * 定義：`(資料日, 今天]` 這個半開區間裡的工作日數。所以「今天的資料」是 0、
     * 週五的資料到下週一是 1。
     *
     * **數的是工作日（週一至週五），刻意不另建交易日曆。** 這是近似，代價要講明：
     *
     * - **國定假日會被算成交易日**，年齡因此偏大、門檻略嚴於同樣數字的真實交易日。
     *   誤差方向是安全的（偏向判成「不評估」），但**農曆年**期間偏差最大——台股
     *   連休約 5 個交易日，那段期間會嚴約 5 天。
     * - **美股套用同一個時區與同一份工作日規則**，不另開分支。美東比台北晚
     *   12–13 小時，所以美股的年齡會比在美國本地算多出約 1 天，同樣偏嚴。
     *
     * 要換成真實交易日曆，得先有一份逐年維護的台／美假日表，而那份表一旦沒跟上
     * 就會**低估**年齡——比目前這個一定偏嚴的近似更危險。
     *
     * 「今天」一律以 {@see TIMEZONE} 判定，與 {@see isStale()} 同源：伺服器跑 UTC
     * 時，台北的隔天凌晨仍是 UTC 的前一天，用伺服器時區會讓年齡每天有 8 小時是錯的。
     *
     * @param  string|null  $date  `Y-m-d` 的資料日（例如日線最後一根 K 棒的日期）
     */
    public static function tradingDayAge(?string $date): ?int
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        $from = CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        // 未來日期（上游時區超前、或資料有誤）夾到 0：負數年齡沒有意義，
        // 而且會讓 `age >= 門檻` 的比較悄悄變成「永遠不過期」。
        $days = (int) $from->diffInDays($today, absolute: false);

        if ($days <= 0) {
            return 0;
        }

        // 整週固定 5 個工作日，剩下不足一週的部分逐日數（最多 6 次）。
        // 逐日走完整段區間在資料停很久時是無界迴圈，所以只走餘數。
        $age = intdiv($days, 7) * 5;
        $weekday = (int) $from->isoWeekday();

        for ($offset = 1; $offset <= $days % 7; $offset++) {
            if ((($weekday - 1 + $offset) % 7) + 1 <= 5) {
                $age++;
            }
        }

        return $age;
    }
}
