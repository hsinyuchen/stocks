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
     */
    private function peer(string $symbol, ?string $industry, ?float $yoy, ?string $asOf = null): Instrument
    {
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
            market: 'tw',
            industry: $industry,
        );

        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => $asOf ?? now()->toDateString(),
            'fetched_at' => now(),
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
            $this->peer("NVDA{$i}", '半導體業', 0.90);
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(0, $result['samples']);
    }

    #[Test]
    public function it_cannot_sample_without_an_industry(): void
    {
        $subject = $this->peer('2330.TW', null, 0.20);

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
            $this->peer("900{$i}.TW", '半導體業', 0.90, now()->subDays($days + 1)->toDateString());
        }

        $result = $this->sampler()->sample($subject, '半導體業');

        $this->assertSame(0, $result['samples'], '過舊的快取列不能當同業樣本');
    }

    #[Test]
    public function it_queries_the_database_only_once_per_industry(): void
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
}
