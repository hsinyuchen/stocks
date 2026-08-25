<?php

namespace Tests\Feature\OrderInventory;

use App\Data\IndustryMomentum;
use App\Data\OrderInventoryData;
use App\Enums\IndustryMomentumUnavailableReason;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\IndustryMomentumSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IndustryMomentumSamplerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 建一檔標的並落一列 fundamentals，其最新月營收 YoY 為 $yoy。
     *
     * $asOf 與 $fetchedAt 必須分開給：兩者在生產環境是不同語意的欄位
     * （資料日 vs 抓取時戳），新鮮度視窗只看後者。
     *
     * @param  ?list<array{month: string, revenue: float, yoy: ?float}>  $monthlyRevenue
     */
    private function peer(
        string $symbol,
        ?string $industry,
        ?float $yoy,
        string $market = 'tw',
        ?\DateTimeInterface $fetchedAt = null,
        ?string $asOf = null,
        ?array $monthlyRevenue = null,
    ): Instrument {
        $instrument = Instrument::factory()->create(['symbol' => $symbol]);

        $data = new OrderInventoryData(
            monthlyRevenue: $monthlyRevenue ?? [
                // 前一個月固定 0.01：實作若取錯月份，中位數會明顯偏離期望值。
                ['month' => '2026-05-01', 'revenue' => 1000.0, 'yoy' => 0.01],
                ['month' => '2026-06-01', 'revenue' => 1000.0, 'yoy' => $yoy],
            ],
            market: $market,
            industry: $industry,
        );

        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => $asOf ?? now()->toDateString(),
            'fetched_at' => $fetchedAt ?? now(),
            'order_inventory' => $data->toArray(),
        ]);

        return $instrument;
    }

    private function sampler(): IndustryMomentumSampler
    {
        return app(IndustryMomentumSampler::class);
    }

    private function floor(): int
    {
        return (int) config('order_inventory.industry_momentum.min_samples');
    }

    #[Test]
    public function it_takes_the_median_of_same_industry_monthly_revenue_growth(): void
    {
        // 自己的 YoY 極端高：若沒排除自己，中位數會是 0.175 而不是 0.15。
        $subject = $this->peer('2330.TW', '半導體業', 0.90);
        $this->peer('2303.TW', '半導體業', 0.05);
        $this->peer('2454.TW', '半導體業', 0.10);
        $this->peer('3034.TW', '半導體業', 0.15);
        $this->peer('2408.TW', '半導體業', 0.20);
        $this->peer('6488.TW', '半導體業', 0.25);

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertTrue($result->applicable);
        $this->assertSame('半導體業', $result->industry);
        $this->assertSame(5, $result->samples);
        $this->assertEqualsWithDelta(0.15, $result->median, 0.0001, '標的自己不得計入產業中位數');
    }

    #[Test]
    public function it_reports_the_excess_over_the_industry_median(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.30);
        $this->peer('2303.TW', '半導體業', 0.05);
        $this->peer('2454.TW', '半導體業', 0.10);
        $this->peer('3034.TW', '半導體業', 0.15);
        $this->peer('2408.TW', '半導體業', 0.20);
        $this->peer('6488.TW', '半導體業', 0.25);

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertEqualsWithDelta(0.30, $result->own, 0.0001);
        $this->assertEqualsWithDelta(0.15, $result->median, 0.0001);
        $this->assertEqualsWithDelta(0.15, $result->excess, 0.0001, '超額 = 自身 YoY − 產業中位數');
    }

    #[Test]
    public function it_reports_the_sample_count_but_no_median_below_the_floor(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.30);

        for ($i = 0; $i < $this->floor() - 1; $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.10);
        }

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertTrue($result->applicable);
        $this->assertSame($this->floor() - 1, $result->samples, '樣本不足仍要照實回報檔數');
        $this->assertNull($result->median);
        $this->assertEqualsWithDelta(0.30, $result->own, 0.0001, '自身 YoY 與樣本數無關，算得出就要給');
        $this->assertNull($result->excess, '沒有中位數就沒有超額可言，不得以 0 代替');
    }

    #[Test]
    public function the_sample_floor_boundary_is_inclusive(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.30);

        for ($i = 0; $i < $this->floor(); $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.10);
        }

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertSame($this->floor(), $result->samples);
        $this->assertNotNull($result->median, '恰好等於下限應給中位數；此斷言釘住 >= 與 > 的差別');
        $this->assertEqualsWithDelta(0.20, $result->excess, 0.0001);
    }

    #[Test]
    public function it_ignores_other_industries_and_other_markets(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.30);

        for ($i = 0; $i < $this->floor(); $i++) {
            $this->peer("800{$i}.TW", '金融保險', 0.90);
        }

        // 美股即使產業字串相同也不得混入——兩市場的營收揭露基準不同。
        foreach (['AMD', 'INTC', 'MU', 'TXN', 'AVGO'] as $symbol) {
            $this->peer($symbol, '半導體業', 0.90, market: 'us');
        }

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertTrue($result->applicable);
        $this->assertSame(0, $result->samples);
        $this->assertNull($result->median);
    }

    #[Test]
    public function it_ignores_rows_whose_market_column_contradicts_the_symbol(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.30);

        // symbol 判台股、JSON 卻寫 us：這列快取本身可疑，不得拿來做跨公司比較。
        for ($i = 0; $i < $this->floor(); $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.90, market: 'us');
        }

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertSame(0, $result->samples);
        $this->assertNull($result->median);
    }

    #[Test]
    public function a_non_taiwan_subject_is_not_applicable_and_is_distinguishable_from_an_empty_sample(): void
    {
        $us = $this->peer('NVDA', '半導體業', 0.30, market: 'us');
        $tw = $this->peer('2330.TW', '半導體業', 0.30);

        $notApplicable = $this->sampler()->forInstrument($us, '半導體業');
        $noSamples = $this->sampler()->forInstrument($tw, '半導體業');

        // 「這個市場沒有這個功能」與「有功能但還沒樣本」語意不同，呈現層要分得出來。
        $this->assertFalse($notApplicable->applicable);
        $this->assertSame(IndustryMomentumUnavailableReason::NotTaiwan, $notApplicable->reason);
        $this->assertSame(0, $notApplicable->samples);
        $this->assertNull($notApplicable->median);
        $this->assertNull($notApplicable->own);

        $this->assertTrue($noSamples->applicable);
        $this->assertNull($noSamples->reason, '有功能但沒樣本時不得填不適用原因');
        $this->assertSame(0, $noSamples->samples);
        $this->assertNull($noSamples->median);
        $this->assertEqualsWithDelta(0.30, $noSamples->own, 0.0001);
    }

    #[Test]
    public function an_unknown_industry_is_not_applicable_for_a_different_reason(): void
    {
        $nullIndustry = $this->peer('2330.TW', null, 0.30);
        $emptyIndustry = $this->peer('2303.TW', '', 0.30);

        $fromNull = $this->sampler()->forInstrument($nullIndustry, null);
        $fromEmpty = $this->sampler()->forInstrument($emptyIndustry, '');

        foreach ([$fromNull, $fromEmpty] as $result) {
            $this->assertFalse($result->applicable);
            $this->assertSame(
                IndustryMomentumUnavailableReason::IndustryUnknown,
                $result->reason,
                '產業未知與非台股是兩種不同的不適用原因',
            );
            $this->assertNull($result->median);
            $this->assertNull($result->own);
            $this->assertSame(0, $result->samples);
        }
    }

    #[Test]
    public function it_skips_peers_whose_monthly_revenue_growth_cannot_be_computed(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.30);
        $this->peer('2303.TW', '半導體業', 0.10);
        $this->peer('2454.TW', '半導體業', 0.20);
        $this->peer('3034.TW', '半導體業', 0.30);
        $this->peer('2408.TW', '半導體業', 0.40);
        $this->peer('6488.TW', '半導體業', 0.50);

        // 最新月缺 YoY（新上市、無去年同月基期）→ 算不出來，不得以 0 計入。
        // 以 0 計入的話樣本會變 6 檔、中位數會從 0.30 掉到 0.25。
        $this->peer('9999.TW', '半導體業', null);

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertSame(5, $result->samples);
        $this->assertEqualsWithDelta(0.30, $result->median, 0.0001, '算不出 YoY 的同業要略過，不是當成 0');
    }

    #[Test]
    public function it_takes_the_latest_month_by_date_not_by_array_position(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.30);

        // 陣列順序與月份順序刻意不一致：最後一個元素是較舊的月份。
        // 用 end() 而不先依 month 排序的話，中位數會變成 0.02。
        $unsorted = [
            ['month' => '2026-06-01', 'revenue' => 1000.0, 'yoy' => 0.50],
            ['month' => '2026-04-01', 'revenue' => 1000.0, 'yoy' => 0.01],
            ['month' => '2026-05-01', 'revenue' => 1000.0, 'yoy' => 0.02],
        ];

        for ($i = 0; $i < $this->floor(); $i++) {
            $this->peer("900{$i}.TW", '半導體業', null, monthlyRevenue: $unsorted);
        }

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertSame($this->floor(), $result->samples);
        $this->assertEqualsWithDelta(0.50, $result->median, 0.0001, '最新月要依 month 判定，不能取陣列最後一筆');
    }

    #[Test]
    public function it_ignores_rows_older_than_the_freshness_window(): void
    {
        $days = (int) config('order_inventory.industry_momentum.freshness_days');
        $subject = $this->peer('2330.TW', '半導體業', 0.30);

        for ($i = 0; $i < $this->floor(); $i++) {
            $this->peer(
                "900{$i}.TW",
                '半導體業',
                0.90,
                fetchedAt: now()->subDays($days + 1)->startOfDay(),
            );
        }

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertSame(0, $result->samples, '過舊的快取列不能當產業樣本');
    }

    #[Test]
    public function the_freshness_window_boundary_is_inclusive(): void
    {
        $days = (int) config('order_inventory.industry_momentum.freshness_days');
        $subject = $this->peer('2330.TW', '半導體業', 0.30);

        // 恰好落在門檻上（視窗下緣切齊當日 00:00）。此斷言釘住 >= 與 > 的差別，
        // 也釘住門檻有切 startOfDay——否則門檻帶著當下時分秒，這幾列會被排除。
        for ($i = 0; $i < $this->floor(); $i++) {
            $this->peer(
                "900{$i}.TW",
                '半導體業',
                0.10,
                fetchedAt: now()->subDays($days)->startOfDay(),
            );
        }

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertSame($this->floor(), $result->samples, '恰好等於視窗下緣的列應納入');
    }

    #[Test]
    public function the_freshness_window_reads_the_fetch_timestamp_not_the_data_date(): void
    {
        $days = (int) config('order_inventory.industry_momentum.freshness_days');
        $staleDataDate = now()->subDays($days + 10)->toDateString();

        // data_as_of 與 fetched_at 同欄不同語意：前者是資料日，後者是抓取時戳。
        // 新鮮度改看 data_as_of 的話，這幾列會全部被排除。
        $subject = $this->peer('2330.TW', '半導體業', 0.30, asOf: $staleDataDate);

        for ($i = 0; $i < $this->floor(); $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.10, asOf: $staleDataDate);
        }

        $result = $this->sampler()->forInstrument($subject, '半導體業');

        $this->assertSame($this->floor(), $result->samples, '新鮮度看抓取時戳，不看資料日');
        $this->assertEqualsWithDelta(0.10, $result->median, 0.0001);
    }

    #[Test]
    public function it_filters_by_industry_in_sql_rather_than_in_php(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.30);
        $this->peer('2303.TW', '半導體業', 0.10);
        $this->peer('8001.TW', '金融保險', 0.90);

        \DB::enableQueryLog();
        $this->sampler()->forInstrument($subject, '半導體業');
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        // 產業述詞必須進 SQL：fundamentals 的索引前導欄是 instrument_id，只靠
        // 時間欄過濾等於全表掃描，而每一列的 order_inventory JSON 都會被 hydrate
        // 成 Eloquent model。拿掉述詞是行為等價的，只有這條白箱斷言抓得到。
        $this->assertCount(1, $queries);
        $this->assertStringContainsString(
            'json_extract',
            $queries[0]['query'],
            '產業過濾要編譯成 JSON 路徑述詞，不能把整個 fundamentals 撈回 PHP 再篩',
        );
        $this->assertContains('半導體業', $queries[0]['bindings']);
    }

    #[Test]
    public function the_same_instance_queries_each_industry_only_once(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.30);
        $second = $this->peer('2303.TW', '半導體業', 0.05);

        for ($i = 0; $i < $this->floor(); $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.10);
        }

        $sampler = $this->sampler();
        $sampler->forInstrument($subject, '半導體業');

        \DB::enableQueryLog();
        $result = $sampler->forInstrument($second, '半導體業');
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertSame([], $queries, '同一次請求內同產業要共用查詢結果');

        // 第二檔的同業是「掃描結果扣掉它自己」＝ 第一檔 + 那 floor 檔。共用的是
        // 掃描結果而不是取樣結果：排除自己那一步必須逐檔重算，否則第二檔會拿到
        // 含自己的中位數。
        $this->assertSame($this->floor() + 1, $result->samples, '共用查詢不得讓排除自己那一步失效');
    }

    #[Test]
    public function the_sampler_is_scoped_to_the_current_request(): void
    {
        // singleton 會讓常駐 worker 跨日沿用同一份樣本，bind 會讓選股器逐檔重建
        // 實例、記憶化失效。先取得實例再 forgetScopedInstances，順序不可顛倒。
        $sampler = app(IndustryMomentumSampler::class);

        $this->assertSame($sampler, app(IndustryMomentumSampler::class), '同一個 scope 內要共用同一份實例');

        app()->forgetScopedInstances();

        $this->assertNotSame($sampler, app(IndustryMomentumSampler::class), 'scope 結束後要重建，不得跨 scope 沿用樣本');
    }

    #[Test]
    public function a_not_applicable_result_carries_no_numbers(): void
    {
        $us = $this->peer('NVDA', '半導體業', 0.30, market: 'us');

        $result = $this->sampler()->forInstrument($us, '半導體業');

        $this->assertInstanceOf(IndustryMomentum::class, $result);
        $this->assertFalse($result->applicable);
        $this->assertNull($result->industry);
        $this->assertNull($result->median);
        $this->assertNull($result->own);
        $this->assertNull($result->excess);
        $this->assertSame(0, $result->samples);
    }
}
