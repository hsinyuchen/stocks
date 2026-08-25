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
    private function news(int $daysAgo, array $symbols): void
    {
        NewsItem::query()->create([
            'title' => "news-{$daysAgo}-".implode('-', $symbols),
            'url' => 'https://example.com/'.uniqid(),
            'source' => 'test',
            'published_at' => $this->now->subDays($daysAgo),
            'related_symbols' => $symbols,
            'relevant' => true,
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
    public function the_window_boundary_is_inclusive_of_the_window_length(): void
    {
        $window = (int) config('order_inventory.social.heat_window_days');

        // 恰好落在視窗邊界上那一天要算進新期。
        $this->news($window, ['2330.TW']);
        $this->news($window + 1, ['2330.TW']);

        $heat = $this->calculator()->forSymbol('2330.TW', $this->now);

        $this->assertSame(1, $heat->recentCount, '恰好 N 日前算新期；此斷言釘住 <= 與 < 的差別');
        $this->assertSame(1, $heat->priorCount);
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
    public function it_queries_the_database_only_once_per_request(): void
    {
        foreach ([1, 2, 3] as $d) {
            $this->news($d, ['2330.TW', '2317.TW']);
        }

        $calculator = $this->calculator();
        $calculator->forSymbol('2330.TW', $this->now);

        \DB::enableQueryLog();
        $calculator->forSymbol('2317.TW', $this->now);
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertSame(
            [],
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
