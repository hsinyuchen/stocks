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

    // ------------------------------------------------------------------
    // 交易日年齡（工作日近似）
    // ------------------------------------------------------------------

    /** 沒有資料日就沒有年齡可言。回 0 會宣稱「今天的資料」，那是假的。 */
    public function test_a_missing_date_has_no_age(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-27 12:00', 'Asia/Taipei'));

        $this->assertNull(DailyDataFreshness::tradingDayAge(null));
        $this->assertNull(DailyDataFreshness::tradingDayAge(''));
    }

    public function test_todays_data_is_zero_trading_days_old(): void
    {
        // 2026-08-27 是週四。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-27 12:00', 'Asia/Taipei'));

        $this->assertSame(0, DailyDataFreshness::tradingDayAge('2026-08-27'));
    }

    /**
     * **週末不算年齡。** 週五收盤到下週一是 1 個工作日，不是 3 個日曆天。
     *
     * 這是整條規則存在的理由：用日曆天數的話，每個週一都會讓所有標的憑空老 2 天，
     * 而市場那兩天根本沒開。
     */
    public function test_a_weekend_costs_nothing(): void
    {
        // 2026-08-21 週五 → 2026-08-24 週一。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 12:00', 'Asia/Taipei'));

        $this->assertSame(1, DailyDataFreshness::tradingDayAge('2026-08-21'));

        // 對照組：同樣 3 個日曆天，但落在週間就是 3 個工作日。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-27 12:00', 'Asia/Taipei'));

        $this->assertSame(3, DailyDataFreshness::tradingDayAge('2026-08-24'));
    }

    /** 跨多週：每整週貢獻 5 個工作日。 */
    public function test_the_age_accumulates_five_per_full_week(): void
    {
        // 2026-08-27 週四；往回推的同一個星期幾。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-27 12:00', 'Asia/Taipei'));

        $this->assertSame(5, DailyDataFreshness::tradingDayAge('2026-08-20'));
        $this->assertSame(10, DailyDataFreshness::tradingDayAge('2026-08-13'));
        $this->assertSame(20, DailyDataFreshness::tradingDayAge('2026-07-30'));
    }

    /** 「今天」是週末時，週五的資料仍是 0 個交易日——中間沒有開過市。 */
    public function test_a_weekend_today_does_not_age_fridays_data(): void
    {
        // 2026-08-22 週六、2026-08-23 週日。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 12:00', 'Asia/Taipei'));

        $this->assertSame(0, DailyDataFreshness::tradingDayAge('2026-08-21'));
    }

    /**
     * 「今天」一律以 Asia/Taipei 判定，與 {@see DailyDataFreshness::TIMEZONE} 同源。
     *
     * 伺服器跑 UTC 時，台北的隔天凌晨仍是 UTC 的前一天；用伺服器時區會讓年齡
     * 在每天台北 08:00 之前少一天。
     */
    public function test_today_is_decided_in_taipei_time(): void
    {
        // UTC 2026-08-26 17:00 ＝ 台北 2026-08-27 01:00（週四）。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 17:00', 'UTC'));

        $this->assertSame(1, DailyDataFreshness::tradingDayAge('2026-08-26'));
    }

    /** 未來日期（上游時區超前、或測資有誤）不得回負數年齡。 */
    public function test_a_future_date_is_clamped_to_zero(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-27 12:00', 'Asia/Taipei'));

        $this->assertSame(0, DailyDataFreshness::tradingDayAge('2026-09-10'));
    }
}
