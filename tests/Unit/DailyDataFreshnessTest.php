<?php

namespace Tests\Unit;

use App\Support\DailyDataFreshness;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * 每日盤後資料的新鮮度。
 *
 * 這個類別是為了修掉一個實際的 bug：伺服器跑 UTC，估值資料 UTC 16:34 抓進來，
 * 24 小時 TTL 讓它要到隔天 UTC 16:34（＝台北 07-30 00:34）才過期——整個 07-29
 * 交易日都拿不到當天資料，即使上游 15:00 就公佈了。
 */
class DailyDataFreshnessTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_missing_fetch_time_is_always_stale(): void
    {
        $this->assertTrue(DailyDataFreshness::isStale(null, 15));
    }

    public function test_before_publish_hour_existing_data_stays_fresh(): void
    {
        // 台北 10:00，今天的資料還沒公佈，手上這份就是最新的。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 10:00', 'Asia/Taipei'));

        $fetched = CarbonImmutable::parse('2026-07-28 16:00', 'Asia/Taipei');

        $this->assertFalse(DailyDataFreshness::isStale($fetched, 15));
    }

    public function test_after_publish_hour_yesterdays_fetch_is_stale(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 15:30', 'Asia/Taipei'));

        $fetched = CarbonImmutable::parse('2026-07-28 16:00', 'Asia/Taipei');

        $this->assertTrue(DailyDataFreshness::isStale($fetched, 15));
    }

    public function test_after_publish_hour_todays_fetch_is_fresh(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 20:00', 'Asia/Taipei'));

        // 已經在公佈後抓過了，同一天不需要再抓。
        $fetched = CarbonImmutable::parse('2026-07-29 15:10', 'Asia/Taipei');

        $this->assertFalse(DailyDataFreshness::isStale($fetched, 15));
    }

    public function test_utc_fetch_time_is_compared_in_taipei_time(): void
    {
        // 這就是原始 bug 的情境：UTC 存的時間必須轉成台北時間再比。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 15:42', 'UTC'));   // 台北 23:42

        $fetched = CarbonImmutable::parse('2026-07-28 16:34', 'UTC');                     // 台北 07-29 00:34

        // 台北 07-29 00:34 抓的，早於當天 15:00 的公佈時刻 → 該重抓。
        $this->assertTrue(DailyDataFreshness::isStale($fetched, 15));
    }

    public function test_fetch_exactly_at_publish_hour_is_fresh(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 18:00', 'Asia/Taipei'));

        $fetched = CarbonImmutable::parse('2026-07-29 15:00', 'Asia/Taipei');

        // 邊界：等於公佈時刻不算「公佈前」。
        $this->assertFalse(DailyDataFreshness::isStale($fetched, 15));
    }

    public function test_publish_hour_is_clamped_to_a_valid_hour(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 12:00', 'Asia/Taipei'));

        // 設定值超出範圍時不得產生跨日的時刻，否則判斷會整個錯亂。
        $this->assertSame('2026-07-29 23:00:00', DailyDataFreshness::todayPublishedAt(99)->toDateTimeString());
        $this->assertSame('2026-07-29 00:00:00', DailyDataFreshness::todayPublishedAt(-5)->toDateTimeString());
    }
}
