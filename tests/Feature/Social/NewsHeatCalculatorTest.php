<?php

namespace Tests\Feature\Social;

use App\Models\NewsItem;
use App\Services\Social\NewsHeatCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NewsHeatCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-08-24 09:00:00');
    }

    /** 在距今 $daysAgo 日發佈一則提及 $symbols 的新聞。 */
    private function news(int $daysAgo, array $symbols, bool $relevant = true): void
    {
        NewsItem::query()->create([
            'title' => "news-{$daysAgo}-".implode('-', $symbols),
            'url' => 'https://example.com/'.uniqid(),
            'source' => 'test',
            'published_at' => $this->now->subDays($daysAgo),
            'related_symbols' => $symbols,
            'relevant' => $relevant,
        ]);
    }

    private function calculator(): NewsHeatCalculator
    {
        return app(NewsHeatCalculator::class);
    }

    #[Test]
    public function it_counts_the_recent_window_against_the_prior_window(): void
    {
        $window = (int) config('order_inventory.social.heat_window_days');

        // 新期 4 則、前期 2 則。
        foreach ([1, 2, 3, 4] as $d) {
            $this->news($d, ['2330.TW']);
        }
        foreach ([$window + 1, $window + 2] as $d) {
            $this->news($d, ['2330.TW']);
        }

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertSame(4, $heat->recentCount);
        $this->assertSame(2, $heat->priorCount);
        $this->assertEqualsWithDelta(1.0, $heat->changeRatio, 0.0001, '4 對 2 是 +100%');
    }

    #[Test]
    public function it_counts_news_published_today_in_the_recent_window(): void
    {
        // 新期從 daysAgo = 0 起算：突發新聞正是熱度訊號最強的時候，
        // 若從 1 起算，生產環境（真實 now）最近 0–24 小時的新聞永遠不算熱度。
        $this->news(0, ['2330.TW']);
        $this->news(0, ['2330.TW']);
        $this->news(1, ['2330.TW']);

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertSame(3, $heat->recentCount, '當天發布的新聞要算進新期');
        $this->assertTrue($heat->hasEnoughSamples);
    }

    #[Test]
    public function the_window_boundary_is_inclusive_of_the_window_length(): void
    {
        $window = (int) config('order_inventory.social.heat_window_days');

        // 新期涵蓋 daysAgo 0 到 $window - 1；恰好落在最後一天要算進新期，
        // 再舊一天則落入前期。
        $this->news($window - 1, ['2330.TW']);
        $this->news($window, ['2330.TW']);

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertSame(1, $heat->recentCount, '恰好第 N-1 日算新期；此斷言釘住 <= 與 < 的差別');
        $this->assertSame(1, $heat->priorCount);
    }

    #[Test]
    public function the_injected_now_determines_which_news_falls_in_the_recent_window(): void
    {
        // 同一批資料、兩個不同的 $now 各算一次。斷言刻意不依賴「凍結日與執行日不同」
        // ——若實作內部改用 now()，兩次呼叫會得到同一個答案，這裡就會紅。
        foreach ([0, 1, 2, 3] as $d) {
            $this->news($d, ['2330.TW']);
        }

        $calculator = $this->calculator();
        $atNow = $calculator->forSymbol('2330.TW', $this->now)->recentCount;
        $aMonthEarlier = $calculator->forSymbol('2330.TW', $this->now->subDays(30))->recentCount;

        $this->assertNotSame(
            $atNow,
            $aMonthEarlier,
            '傳入的 now 必須決定視窗位置；相同資料在不同基準日不可能得到相同則數',
        );
        $this->assertSame(4, $atNow);
        $this->assertSame(0, $aMonthEarlier, '基準日之後才發布的新聞不算數');
    }

    #[Test]
    public function it_reports_insufficient_samples_below_the_floor_but_still_counts(): void
    {
        $floor = (int) config('order_inventory.social.min_recent_mentions');

        for ($i = 1; $i <= $floor - 1; $i++) {
            $this->news($i, ['2330.TW']);
        }

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertFalse($heat->hasEnoughSamples);
        $this->assertSame($floor - 1, $heat->recentCount, '樣本不足也要照實回報則數，呈現層要說得出「只有 N 則」');
    }

    #[Test]
    public function the_sample_floor_boundary_is_inclusive(): void
    {
        $floor = (int) config('order_inventory.social.min_recent_mentions');

        for ($i = 1; $i <= $floor; $i++) {
            $this->news($i, ['2330.TW']);
        }

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertTrue($heat->hasEnoughSamples, '恰好等於下限算足夠；釘住 >= 與 > 的差別');
    }

    #[Test]
    public function a_prior_window_of_zero_does_not_divide_by_zero(): void
    {
        foreach ([1, 2, 3] as $d) {
            $this->news($d, ['2330.TW']);
        }

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertSame(0, $heat->priorCount);
        $this->assertNull(
            $heat->changeRatio,
            '前期為 0 時變化率無定義，回 null 不編造數字；classifier 另有 fromZero 判準',
        );
        $this->assertTrue($heat->roseFromZero);
    }

    #[Test]
    public function it_ignores_other_symbols(): void
    {
        foreach ([1, 2, 3, 4] as $d) {
            $this->news($d, ['2317.TW']);
        }

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertSame(0, $heat->recentCount);
        $this->assertFalse($heat->hasEnoughSamples);
    }

    #[Test]
    public function it_ignores_news_marked_irrelevant(): void
    {
        // relevant = false 是「美食、社會案件、生活理財」那類雜訊，
        // 算進熱度會讓一檔標的因為非投資新聞被誤判升溫。
        $this->news(1, ['2330.TW']);
        $this->news(2, ['2330.TW'], relevant: false);
        $this->news(3, ['2330.TW'], relevant: false);

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertSame(1, $heat->recentCount, '只有 relevant 的新聞算熱度');
    }

    #[Test]
    public function one_article_mentioning_several_symbols_counts_for_each(): void
    {
        foreach ([1, 2, 3] as $d) {
            $this->news($d, ['2330.TW', '2317.TW']);
        }

        $calculator = $this->calculator();

        $this->assertSame(3, $calculator->forSymbol('2330.TW', $this->now)->recentCount);
        $this->assertSame(3, $calculator->forSymbol('2317.TW', $this->now)->recentCount);
    }

    #[Test]
    public function it_computes_the_high_water_percentile_over_the_longer_window(): void
    {
        $long = (int) config('order_inventory.social.high_water_window_days');
        $window = (int) config('order_inventory.social.heat_window_days');

        // 長視窗內每天 1 則，最後 $window 天內額外加碼，讓新期明顯高於歷史分佈。
        for ($d = 1; $d <= $long; $d++) {
            $this->news($d, ['2330.TW']);
        }
        foreach (range(1, $window) as $d) {
            $this->news($d, ['2330.TW']);
            $this->news($d, ['2330.TW']);
        }

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertNotNull($heat->highWaterThreshold);
        $this->assertTrue(
            $heat->isHighWater,
            '新期則數應達到近 '.$long.' 日分佈的高檔門檻',
        );
    }

    #[Test]
    public function it_cannot_judge_high_water_without_enough_history(): void
    {
        foreach ([1, 2, 3] as $d) {
            $this->news($d, ['2330.TW']);
        }

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertNull(
            $heat->highWaterThreshold,
            '歷史長度不足時不給高檔門檻，不可用短樣本硬算百分位',
        );
        $this->assertFalse($heat->isHighWater);
    }

    #[Test]
    public function two_full_segments_of_history_cannot_judge_high_water(): void
    {
        $window = (int) config('order_inventory.social.heat_window_days');

        // 最舊一則在 $window * 2 - 1 日前：資料恰好涵蓋 2 整段，且兩段都有則數。
        $this->news(0, ['2330.TW']);
        $this->news($window * 2 - 1, ['2330.TW']);

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertNull(
            $heat->highWaterThreshold,
            '恰好 2 段仍低於百分位所需的最小段數；此斷言釘住該常數的實際值',
        );
        $this->assertFalse($heat->isHighWater);
    }

    #[Test]
    public function three_full_segments_of_history_can_judge_high_water(): void
    {
        $window = (int) config('order_inventory.social.heat_window_days');

        // 最舊一則在 $window * 3 - 1 日前：資料恰好涵蓋 3 整段，且每段都有則數。
        $this->news(0, ['2330.TW']);
        $this->news($window + 6, ['2330.TW']);
        $this->news($window * 3 - 1, ['2330.TW']);

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertNotNull(
            $heat->highWaterThreshold,
            '恰好 3 段就足以算百分位；與上一支測試合起來釘住最小段數正好是 3',
        );
    }

    #[Test]
    public function two_populated_segments_out_of_three_cannot_judge_high_water(): void
    {
        $window = (int) config('order_inventory.social.heat_window_days');

        // 段數夠（3 整段），但中間那段掛零，真正有則數的只有 2 段——恰好比
        // 最小段數少 1。這是緊貼常數下方的值：非零段數守門若只弱化 1（例如
        // 比較式改成 MIN - 1），端點測試（1 段、3 段）都還是綠的，只有這裡會紅。
        $this->news(0, ['2330.TW']);
        $this->news($window * 3 - 1, ['2330.TW']);

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertNull(
            $heat->highWaterThreshold,
            '非零段數恰好比最小段數少 1 仍不給門檻；此斷言釘住非零段數走的是同一個常數',
        );
        $this->assertFalse($heat->isHighWater);
    }

    /**
     * 全零分佈**在任何百分位下**都不是高檔。
     *
     * 這個 fixture 的 `nonZeroSegments` 是 0，所以實際接住它的是非零段數那道守門，
     * `$threshold <= 0` 那道走不到——兩道互為backstop，只刪一道這條測試不會紅
     * （非零段數那道由 two_populated_segments_out_of_three… 與
     * a_lone_recent_mention… 釘住，`$threshold <= 0` 那道由
     * a_threshold_landing_on_an_empty_segment… 與
     * a_low_percentile_on_three_segments… 釘住）。本條釘的是**兩道一起拿掉**的
     * 情形，並且把「與百分位無關」寫成斷言：掃過整個百分位範圍，包含會讓
     * nearest-rank 落到最低段的那些值。
     */
    #[Test]
    public function an_all_zero_distribution_is_not_a_high_water_mark(): void
    {
        // 唯一一則落在被捨棄的殘段裡：比較視窗內每一段都是 0 則。
        // 門檻若照算會是 0.0，而 0 >= 0 會讓「零則新聞」被宣告為熱度高檔。
        $this->news(45, ['2330.TW']);

        foreach ([1.0, 20.0, 50.0, 80.0, 100.0] as $percentile) {
            config(['order_inventory.social.high_water_percentile' => $percentile]);

            $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

            $this->assertSame(0, $heat->recentCount);
            $this->assertNull(
                $heat->highWaterThreshold,
                sprintf('全零分佈沒有門檻可言（p%s），0.0 不是門檻；null 才代表「算不出來」', $percentile),
            );
            $this->assertFalse($heat->isHighWater, '零則新聞不可能是熱度高檔');
        }
    }

    /**
     * **這是唯一走得到 `$threshold <= 0` 那道守門的 fixture，而且非調低百分位不可。**
     *
     * 走到那道守門的條件是「nearest-rank 落在一個 0 則的段上，且非零段數仍達 3」。
     * 段數只可能是 3 或 4（high_water_window_days / heat_window_days = 60 / 14，
     * 且 MIN_SEGMENTS_FOR_PERCENTILE = 3）：3 段時三段都得非零，沒有空白段可落；
     * 4 段時必須恰有一段為零，而出貨設定的 p80 一律指向**最大**段——最大段為 0
     * 就代表全零分佈，會先被非零段數那道接住。所以在 p80 下這道守門不可達，
     * 得把百分位調到 ≤25（4 段時 rank 才會是 1）才逼得出來。
     * 詳見 config/order_inventory.php 的 high_water_percentile 註解。
     */
    #[Test]
    public function a_threshold_landing_on_an_empty_segment_is_not_a_high_water_mark(): void
    {
        $window = (int) config('order_inventory.social.heat_window_days');
        // 低百分位會讓 nearest-rank 落在分佈最低的那一段上。該段是 0 則時，
        // 門檻會是 0.0，於是 0 >= 0 又把空白宣告成高檔——與全零分佈同一個坑，
        // 只是預設的 80 百分位剛好踩不到，所以要用設定值把它逼出來。
        config(['order_inventory.social.high_water_percentile' => 20]);

        // 前 3 段各 1 則、第 4 段（$window * 3 到 $window * 4 - 1）掛零；
        // 最舊一則落在第 4 段之後被捨棄的殘段裡，只用來把可用段數推到 4。
        $this->news(0, ['2330.TW']);
        $this->news($window, ['2330.TW']);
        $this->news($window * 2, ['2330.TW']);
        $this->news($window * 4, ['2330.TW']);

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertNull(
            $heat->highWaterThreshold,
            '百分位落在空白段上時沒有門檻可言；0.0 不是門檻',
        );
        $this->assertFalse($heat->isHighWater);
    }

    /**
     * **現行視窗設定下 high_water_percentile 是個退化旋鈕：p80 與 p100 等價。**
     *
     * 段數 = high_water_window_days / heat_window_days = 60 / 14 → 最多 4 段，
     * 而 MIN_SEGMENTS_FOR_PERCENTILE 要求至少 3 段，所以段數只可能是 3 或 4。
     * nearest-rank 下 3 段時 p67–p100、4 段時 p76–p100 都指向最大段，80 落在
     * 兩者的交集裡。`isHighWater` 的真正語意因此是「本期是近 3–4 個視窗中最高的
     * （含並列）」，不是「達到 80 百分位」。
     *
     * 這是個令人意外的事實，寫成測試才不會被後人當成 bug 亂改；同時它會在有人
     * 把 high_water_percentile 調到 75 以下（4 段時就不再是最大段）或把
     * high_water_window_days 拉長到切得出第 5 段時變紅，逼下一位維護者重新確認
     * 呈現層的文案還說不說得通。
     */
    #[Test]
    public function the_configured_percentile_is_equivalent_to_taking_the_maximum_segment(): void
    {
        $window = (int) config('order_inventory.social.heat_window_days');

        // 3 段（最舊一則在第 3 段內）：各段 3／1／2 則，最大段 3 則。
        foreach ([0, 1, 2] as $daysAgo) {
            $this->news($daysAgo, ['2330.TW']);
        }
        $this->news($window, ['2330.TW']);
        $this->news($window * 2, ['2330.TW']);
        $this->news($window * 3 - 1, ['2330.TW']);

        // 4 段（最舊一則落在被捨棄的殘段裡，只用來把可用段數推到 4）：
        // 各段 2／1／3／1 則，最大段 3 則。
        foreach ([0, 1] as $daysAgo) {
            $this->news($daysAgo, ['2454.TW']);
        }
        $this->news($window, ['2454.TW']);
        foreach ([$window * 2, $window * 2 + 1, $window * 2 + 2] as $daysAgo) {
            $this->news($daysAgo, ['2454.TW']);
        }
        $this->news($window * 3, ['2454.TW']);
        $this->news($window * 4, ['2454.TW']);

        $configured = (float) config('order_inventory.social.high_water_percentile');

        foreach (['2330.TW' => 3, '2454.TW' => 4] as $symbol => $segments) {
            $atConfigured = $this->thresholdAt($configured, $symbol);
            $atMaximum = $this->thresholdAt(100.0, $symbol);

            $this->assertSame(
                3.0,
                $atConfigured,
                sprintf('%d 段時設定的百分位落在最大段（3 則）上，不是某個中間段', $segments),
            );
            $this->assertSame(
                $atMaximum,
                $atConfigured,
                sprintf('%d 段時 p%s 與 p100 等價——這個旋鈕在現行視窗設定下只有 3–4 個有效檔位', $segments, $configured),
            );
        }
    }

    /** 以指定百分位重算某個 symbol 的高檔門檻。 */
    private function thresholdAt(float $percentile, string $symbol): ?float
    {
        config(['order_inventory.social.high_water_percentile' => $percentile]);

        return $this->calculator()->forSymbol($symbol, $this->now)->highWaterThreshold;
    }

    #[Test]
    public function a_lone_recent_mention_is_not_a_high_water_mark(): void
    {
        // 分佈只有一段非零（就是新期自己）：門檻等於拿自己跟自己比，
        // 剛被報導的標的會立刻變成「高檔」。
        $this->news(50, ['2330.TW']);
        $this->news(1, ['2330.TW']);

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertSame(1, $heat->recentCount);
        $this->assertNull(
            $heat->highWaterThreshold,
            '非零段數不足時不給門檻，否則單一則新聞會自我認證為高檔',
        );
        $this->assertFalse($heat->isHighWater);
    }

    #[Test]
    public function it_queries_the_database_only_once_per_request(): void
    {
        foreach ([1, 2, 3] as $d) {
            $this->news($d, ['2330.TW', '2317.TW']);
        }

        $calculator = $this->calculator();

        // 查詢記錄要從「第一次呼叫之前」就開始，否則只驗到第二次是 0 次，
        // 第一次打了幾次（例如 per-symbol 查詢）根本不在斷言範圍內。
        \DB::enableQueryLog();
        $calculator->forSymbol('2330.TW', $this->now);
        $calculator->forSymbol('2317.TW', $this->now);
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertCount(
            1,
            $queries,
            '選股器逐檔呼叫，同一次掃描必須共用同一次查詢——否則 100 檔會打 100 次',
        );
    }

    #[Test]
    public function it_is_bound_as_scoped_not_singleton(): void
    {
        // 注意：brief 原稿在 forgetScopedInstances() 之後用兩個「就地呼叫」比較
        // （assertNotSame(app(...), app(...))）。PHP 對函式引數是循序求值，第二個
        // app() 呼叫的當下，第一個呼叫已經把新實例寫回容器的 instances 快取，
        // 兩者必然相同——這種寫法對任何 scoped／singleton 綁定都不可能通過，
        // 與同一份測試前半段要求「同一 scope 內兩次解析須相同」互相矛盾。
        // 改成與 OrderInventoryPeerSamplerTest::the_sampler_is_scoped_to_the_current_request
        // 一致的寫法：比較「忘記前捕捉的實例」與「忘記後新解析的實例」。
        $instance = app(NewsHeatCalculator::class);

        $this->assertSame($instance, app(NewsHeatCalculator::class), '同一個 scope 內要共用同一份實例');

        app()->forgetScopedInstances();

        $this->assertNotSame(
            $instance,
            app(NewsHeatCalculator::class),
            'singleton 會讓常駐 worker 跨日沿用同一份新聞快照',
        );
    }
}
