<?php

namespace Tests\Feature\OrderInventory;

use App\Contracts\CompanyFinancialsProvider;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\OrderInventoryAssessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryAssessorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 造一份可評級的台股序列（落在規則 4 → B 級）。
     *
     * 開頭補一季 2025Q2：revenueYoy 是往前**正好四季**的同季基期查找
     * （OrderInventoryMetricsCalculator::calculate() 的 quarterAt(-4)），只有
     * 2026Q1／2026Q2 兩季時該基期查無此季，revenueYoy 恆為 null，C10
     * （比較同業營收年增中位數）永遠算不出來，與同業樣本是否可得無關。序列
     * 允許缺季（DTO 文件化的不變式），故只補這一季、不需要 2025Q3／2025Q4
     * 兩季連續。
     */
    private function ratableSeries(): OrderInventoryData
    {
        return new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(period: '2025Q2', revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0),
                new QuarterlyFinancials(period: '2026Q1', revenue: 1000.0, costOfGoodsSold: 700.0, grossProfit: 300.0, inventories: 350.0),
                new QuarterlyFinancials(
                    period: '2026Q2',
                    endDate: now()->toDateString(),
                    revenue: 1000.0, costOfGoodsSold: 700.0, grossProfit: 300.0, inventories: 350.0,
                ),
            ],
            market: 'tw',
            industry: '半導體業',
        );
    }

    private function bindProvider(OrderInventoryData $data): Instrument
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $this->app->bind(CompanyFinancialsProvider::class, fn (): CompanyFinancialsProvider => new class($data) implements CompanyFinancialsProvider
        {
            public function __construct(private readonly OrderInventoryData $data) {}

            public function financials(string $symbol, int $months): OrderInventoryData
            {
                return $this->data;
            }
        });

        return $instrument;
    }

    #[Test]
    public function it_returns_null_when_there_is_no_series(): void
    {
        $instrument = $this->bindProvider(OrderInventoryData::empty());

        $this->assertNull(app(OrderInventoryAssessor::class)->forInstrument($instrument));
    }

    #[Test]
    public function it_persists_the_rating_onto_the_existing_row(): void
    {
        $instrument = $this->bindProvider($this->ratableSeries());

        $before = Fundamental::query()->where('instrument_id', $instrument->id)->count();
        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);
        $after = Fundamental::query()->where('instrument_id', $instrument->id)->get();

        $this->assertNotNull($result);
        $this->assertSame(
            $before === 0 ? 1 : $before,
            $after->count(),
            '寫回評級不得新增列——只更新 orderInventoryFor() 已落地的那一列',
        );
        $this->assertSame($result['assessment']->rating->value, $after->last()->order_inventory_rating);
    }

    #[Test]
    public function the_first_assessment_reports_first(): void
    {
        $instrument = $this->bindProvider($this->ratableSeries());

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertSame('first', $result['assessment']->ratingChange);
    }

    #[Test]
    public function it_compares_against_the_previous_data_day_not_todays_own_row(): void
    {
        $instrument = $this->bindProvider($this->ratableSeries());

        // 前一個資料日留了一筆 C 級。
        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => now()->subDay()->toDateString(),
            'fetched_at' => now()->subDay(),
            'order_inventory_rating' => 'C',
        ]);

        $assessor = app(OrderInventoryAssessor::class);
        $first = $assessor->forInstrument($instrument);

        $this->assertSame('upgraded', $first['assessment']->ratingChange);

        // 同一天再跑一次：前次評級仍應是昨天那筆 C，不是今天自己剛寫的 B。
        $second = $assessor->forInstrument($instrument);

        $this->assertSame(
            'upgraded',
            $second['assessment']->ratingChange,
            '同日重跑不得把自己剛寫的值當前次評級，否則永遠是 unchanged',
        );
    }

    #[Test]
    public function it_reports_the_peer_sample_count(): void
    {
        $instrument = $this->bindProvider($this->ratableSeries());

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertArrayHasKey('peer_samples', $result);
        $this->assertSame(0, $result['peer_samples'], '沒有同業快取時樣本數為 0，而不是缺這個鍵');
    }

    #[Test]
    public function it_feeds_the_peer_median_into_the_assessment(): void
    {
        $instrument = $this->bindProvider($this->ratableSeries());
        $floor = (int) config('order_inventory.peer.min_samples');

        // 造足量同業，年增全為 0.50，遠高於標的的 0（兩季營收持平）。
        for ($i = 0; $i < $floor; $i++) {
            $peer = Instrument::factory()->create(['symbol' => "900{$i}.TW"]);
            Fundamental::query()->create([
                'instrument_id' => $peer->id,
                'data_as_of' => now()->toDateString(),
                'fetched_at' => now(),
                'order_inventory' => (new OrderInventoryData(
                    quarters: [
                        new QuarterlyFinancials(period: '2025Q2', revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0),
                        new QuarterlyFinancials(period: '2026Q2', revenue: 1500.0, costOfGoodsSold: 700.0, inventories: 350.0),
                    ],
                    market: 'tw',
                    industry: '半導體業',
                ))->toArray(),
            ]);
        }

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertSame($floor, $result['peer_samples']);
        $this->assertFalse(
            $result['assessment']->conditions['C10'],
            '同業中位數已可得，C10 必須從 null 變成明確的 false',
        );
    }
}
