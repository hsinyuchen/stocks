<?php

namespace Tests\Feature\OrderInventory;

use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\OrderInventoryPeerSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryPeerSamplerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 建一檔標的並落一列 fundamentals，其最新季營收年增為 $yoy。
     * 序列固定兩年同季，讓 revenueYoy 精確等於 $yoy。
     *
     * $asOf 與 $fetchedAt 必須分開給：兩者在生產環境是不同語意的欄位
     * （資料日 vs 抓取時戳），新鮮度視窗只看後者。
     */
    private function peer(
        string $symbol,
        ?string $industry,
        ?float $yoy,
        ?string $asOf = null,
        string $market = 'tw',
        ?\DateTimeInterface $fetchedAt = null,
    ): Instrument {
        $instrument = Instrument::factory()->create(['symbol' => $symbol]);

        $base = 1000.0;
        $data = new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(period: '2025Q2', revenue: $base, costOfGoodsSold: 700.0, inventories: 350.0),
                new QuarterlyFinancials(period: '2025Q3', revenue: $base, costOfGoodsSold: 700.0, inventories: 350.0),
                new QuarterlyFinancials(period: '2025Q4', revenue: $base, costOfGoodsSold: 700.0, inventories: 350.0),
                new QuarterlyFinancials(period: '2026Q1', revenue: $base, costOfGoodsSold: 700.0, inventories: 350.0),
                new QuarterlyFinancials(
                    period: '2026Q2',
                    revenue: $yoy === null ? null : $base * (1 + $yoy),
                    costOfGoodsSold: 700.0,
                    inventories: 350.0,
                ),
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

    private function sampler(): OrderInventoryPeerSampler
    {
        return app(OrderInventoryPeerSampler::class);
    }

    #[Test]
    public function it_takes_the_median_of_same_industry_peers(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.20);
        $this->peer('2303.TW', '半導體業', 0.05);
        $this->peer('2454.TW', '半導體業', 0.10);
        $this->peer('3034.TW', '半導體業', 0.15);
        $this->peer('2408.TW', '半導體業', 0.20);
        $this->peer('6488.TW', '半導體業', 0.25);

        $result = $this->sampler()->sample($subject, '半導體業');

        // 同業五檔（不含自己）：0.05 / 0.10 / 0.15 / 0.20 / 0.25 → 中位數 0.15
        $this->assertSame(5, $result['samples']);
        $this->assertEqualsWithDelta(0.15, $result['median'], 0.0001);
    }

    #[Test]
    public function it_takes_the_median_not_the_mean_of_a_skewed_peer_set(): void
    {
        // 偏態樣本：中位數 0.10，平均數 0.30。這一條釘住「取中位數」不是「取平均」
        // ——對稱樣本兩者相等，證偽不了任何東西。
        $subject = $this->peer('2330.TW', '半導體業', 0.20);
        $this->peer('2303.TW', '半導體業', 0.10);
        $this->peer('2454.TW', '半導體業', 0.10);
        $this->peer('3034.TW', '半導體業', 0.10);
        $this->peer('2408.TW', '半導體業', 0.10);
        $this->peer('6488.TW', '半導體業', 1.10);

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(5, $result['samples']);
        $this->assertEqualsWithDelta(0.10, $result['median'], 0.0001, '離群值只能移動中位數的位置，不能把它拉到平均數');
    }

    #[Test]
    public function it_averages_the_two_middle_values_when_the_peer_count_is_even(): void
    {
        // 偶數筆：0.02 / 0.04 / 0.06 / 0.20 / 0.30 / 0.40 → (0.06 + 0.20) / 2 = 0.13。
        // 只取右中會得到 0.20、取平均會得到 0.17，三者互相區分得開。
        $subject = $this->peer('2330.TW', '半導體業', 0.20);
        $this->peer('2303.TW', '半導體業', 0.02);
        $this->peer('2454.TW', '半導體業', 0.04);
        $this->peer('3034.TW', '半導體業', 0.06);
        $this->peer('2408.TW', '半導體業', 0.20);
        $this->peer('6488.TW', '半導體業', 0.30);
        $this->peer('3661.TW', '半導體業', 0.40);

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(6, $result['samples']);
        $this->assertEqualsWithDelta(0.13, $result['median'], 0.0001, '偶數筆要取中間兩筆平均');
    }

    #[Test]
    public function it_excludes_the_subject_from_its_own_peer_set(): void
    {
        // 自己的年增極端高；若沒排除，中位數會被自己拉高。
        $subject = $this->peer('2330.TW', '半導體業', 5.00);
        $this->peer('2303.TW', '半導體業', 0.10);
        $this->peer('2454.TW', '半導體業', 0.10);
        $this->peer('3034.TW', '半導體業', 0.10);
        $this->peer('2408.TW', '半導體業', 0.10);
        $this->peer('6488.TW', '半導體業', 0.10);

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(5, $result['samples']);
        $this->assertEqualsWithDelta(0.10, $result['median'], 0.0001, '自己不得計入同業樣本');
    }

    #[Test]
    public function it_returns_a_null_median_when_samples_are_below_the_floor(): void
    {
        $floor = (int) config('order_inventory.peer.min_samples');
        $subject = $this->peer('2330.TW', '半導體業', 0.20);

        for ($i = 0; $i < $floor - 1; $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.10);
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame($floor - 1, $result['samples']);
        $this->assertNull($result['median'], '樣本不足時不給中位數，但樣本數要照實回報');
    }

    #[Test]
    public function the_sample_floor_boundary_is_inclusive(): void
    {
        $floor = (int) config('order_inventory.peer.min_samples');
        $subject = $this->peer('2330.TW', '半導體業', 0.20);

        for ($i = 0; $i < $floor; $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.10);
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame($floor, $result['samples']);
        $this->assertNotNull($result['median'], '恰好等於下限應給中位數；此斷言釘住 >= 與 > 的差別');
    }

    #[Test]
    public function it_caps_the_peer_set_at_the_configured_maximum(): void
    {
        config(['order_inventory.peer.max_samples' => 3]);

        for ($i = 0; $i < 6; $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.10);
        }

        // 標的最後建，讓它排在取樣順序最前面（最近抓取優先）。上限若在排除自己
        // **之前**就截斷，標的自己這一列會佔掉一個名額，實際同業上限變成
        // max_samples - 1，這裡就會只拿到 2 檔。
        $subject = $this->peer('2330.TW', '半導體業', 0.20);

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(3, $result['samples'], '上限截的是排除自己之後的同業數，不是設定值減一');
    }

    #[Test]
    public function it_ignores_other_industries(): void
    {
        $floor = (int) config('order_inventory.peer.min_samples');
        $subject = $this->peer('2330.TW', '半導體業', 0.20);

        for ($i = 0; $i < $floor; $i++) {
            $this->peer("800{$i}.TW", '金融保險', 0.90);
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(0, $result['samples']);
        $this->assertNull($result['median']);
    }

    #[Test]
    public function it_ignores_other_markets(): void
    {
        $floor = (int) config('order_inventory.peer.min_samples');
        $subject = $this->peer('2330.TW', '半導體業', 0.20);

        // 美股標的即使產業字串相同也不得混入——兩市場的營收季節性與揭露基準不同。
        for ($i = 0; $i < $floor; $i++) {
            $this->peer("NVDA{$i}", '半導體業', 0.90, market: 'us');
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(0, $result['samples']);
        $this->assertNull($result['median']);
    }

    #[Test]
    public function it_ignores_rows_whose_market_column_contradicts_the_symbol(): void
    {
        $floor = (int) config('order_inventory.peer.min_samples');
        $subject = $this->peer('2330.TW', '半導體業', 0.20);

        // symbol 判台股、JSON 卻寫 us：這列快取本身可疑（資料重放、跨環境匯入），
        // 拿它做跨公司比較不安全。機會性計算丟掉一檔的成本是 0。
        for ($i = 0; $i < $floor; $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.90, market: 'us');
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(0, $result['samples']);
        $this->assertNull($result['median']);
    }

    #[Test]
    public function it_ignores_rows_whose_symbol_contradicts_the_market_column(): void
    {
        $floor = (int) config('order_inventory.peer.min_samples');
        $subject = $this->peer('2330.TW', '半導體業', 0.20);

        // 反方向的不一致：JSON 寫 tw、symbol 卻是美股。symbol 才是權威（讀取時
        // 重新推導），JSON 欄位只是抓取當下回填的反正規化快照。
        for ($i = 0; $i < $floor; $i++) {
            $this->peer("NVDA{$i}", '半導體業', 0.90, market: 'tw');
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(0, $result['samples']);
        $this->assertNull($result['median']);
    }

    #[Test]
    public function it_cannot_sample_without_an_industry(): void
    {
        $subject = $this->peer('2330.TW', null, 0.20);

        // 產業別未知的干擾樣本：守衛拿掉的話，null === null 會讓它們全被當成
        // 「同產業」納入。沒有這幾檔的話，光靠排除自己就會得到 0 檔，守衛在不在
        // 都一樣。
        for ($i = 0; $i < 5; $i++) {
            $this->peer("900{$i}.TW", null, 0.90);
        }

        $result = $this->sampler()->sample($subject, null);

        $this->assertSame(0, $result['samples']);
        $this->assertNull($result['median'], '產業未知就沒有「同業」可言，不能拿全市場當同業');
    }

    #[Test]
    public function it_skips_peers_whose_revenue_growth_cannot_be_computed(): void
    {
        $floor = (int) config('order_inventory.peer.min_samples');
        $subject = $this->peer('2330.TW', '半導體業', 0.20);

        for ($i = 0; $i < $floor; $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.10);
        }

        // 最新季營收為 null → 算不出年增，不得以 0 計入而把中位數拉低。
        $this->peer('9999.TW', '半導體業', null);

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame($floor, $result['samples']);
        $this->assertEqualsWithDelta(0.10, $result['median'], 0.0001);
    }

    #[Test]
    public function it_ignores_rows_older_than_the_freshness_window(): void
    {
        $floor = (int) config('order_inventory.peer.min_samples');
        $days = (int) config('order_inventory.peer.freshness_days');
        $subject = $this->peer('2330.TW', '半導體業', 0.20);

        for ($i = 0; $i < $floor; $i++) {
            $this->peer(
                "900{$i}.TW",
                '半導體業',
                0.90,
                fetchedAt: now()->subDays($days + 1)->startOfDay(),
            );
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(0, $result['samples'], '過舊的快取列不能當同業樣本');
    }

    #[Test]
    public function the_freshness_window_boundary_is_inclusive(): void
    {
        $floor = (int) config('order_inventory.peer.min_samples');
        $days = (int) config('order_inventory.peer.freshness_days');
        $subject = $this->peer('2330.TW', '半導體業', 0.20);

        // 恰好落在門檻上（視窗下緣切齊當日 00:00）。此斷言釘住 >= 與 > 的差別，
        // 也釘住門檻有切 startOfDay——否則門檻帶著當下時分秒，這幾列會被排除。
        for ($i = 0; $i < $floor; $i++) {
            $this->peer(
                "900{$i}.TW",
                '半導體業',
                0.10,
                fetchedAt: now()->subDays($days)->startOfDay(),
            );
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame($floor, $result['samples'], '恰好等於視窗下緣的列應納入');
    }

    #[Test]
    public function it_samples_us_peers_whose_quarter_end_predates_the_freshness_window(): void
    {
        // data_as_of 在美股是季末日：10-Q 依 SEC 規定在季末後 40–45 天才送件，
        // 所以美股列一落地 data_as_of 就已超出 30 天視窗。新鮮度必須看 fetched_at，
        // 否則所有美股標的的同業樣本恆為 0 檔。
        $staleQuarterEnd = now()->subDays(40)->toDateString();

        $subject = $this->peer('NVDA', '半導體業', 0.20, $staleQuarterEnd, 'us');

        foreach (['AMD', 'INTC', 'MU', 'TXN', 'AVGO'] as $symbol) {
            $this->peer($symbol, '半導體業', 0.10, $staleQuarterEnd, 'us');
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(5, $result['samples'], '美股列的新鮮度看抓取時戳，不看季末日');
        $this->assertEqualsWithDelta(0.10, $result['median'], 0.0001);
    }

    #[Test]
    public function it_filters_by_industry_in_sql_rather_than_in_php(): void
    {
        $subject = $this->peer('2330.TW', '半導體業', 0.20);
        $this->peer('2303.TW', '半導體業', 0.10);
        $this->peer('8001.TW', '金融保險', 0.90);

        \DB::enableQueryLog();
        $this->sampler()->sample($subject, '半導體業');
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        // 產業述詞必須進 SQL：fundamentals 的索引前導欄是 instrument_id，只靠
        // 時間欄過濾等於全表掃描，而每一列的 order_inventory JSON 都會被 hydrate
        // 成 Eloquent model。台股每日寫一列，30 天視窗內每檔就有約 30 列，
        // 再乘上選股器逐產業掃描的次數。
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
        $floor = (int) config('order_inventory.peer.min_samples');
        $subject = $this->peer('2330.TW', '半導體業', 0.20);
        $second = $this->peer('2303.TW', '半導體業', 0.05);

        for ($i = 0; $i < $floor; $i++) {
            $this->peer("900{$i}.TW", '半導體業', 0.10);
        }

        $sampler = $this->sampler();
        $sampler->sample($subject, '半導體業');

        \DB::enableQueryLog();
        $sampler->sample($second, '半導體業');
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertSame(
            [],
            $queries,
            '同一次請求內同產業要共用查詢結果——選股器逐檔呼叫，否則 100 檔會打 100 次同樣的查詢',
        );
    }

    #[Test]
    public function the_sampler_is_scoped_to_the_current_request(): void
    {
        // 上面那條驗的是實例層記憶化，碰不到容器生命週期。這條才是釘住綁定寫的是
        // scoped：singleton 會讓常駐 worker 跨日沿用同一份樣本，bind 會讓選股器
        // 逐檔重建實例、記憶化失效。
        $sampler = app(OrderInventoryPeerSampler::class);

        $this->assertSame($sampler, app(OrderInventoryPeerSampler::class), '同一個 scope 內要共用同一份實例');

        app()->forgetScopedInstances();

        $this->assertNotSame($sampler, app(OrderInventoryPeerSampler::class), 'scope 結束後要重建，不得跨 scope 沿用樣本');
    }
}
