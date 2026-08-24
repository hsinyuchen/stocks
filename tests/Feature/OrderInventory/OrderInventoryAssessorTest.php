<?php

namespace Tests\Feature\OrderInventory;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FundamentalsProvider;
use App\Data\FundamentalsData;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\OrderInventoryAssessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryAssessorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 時間凍結在 2026Q2 季末後 55 天。
     *
     * 兩個理由：
     * 1. 時效判定（max_quarter_age_days = 228 天）比的是 now()，序列用寫死的
     *    季末日時，不凍結的測試會在某個日期之後一律評成 insufficient——壞在
     *    日曆上而不是壞在程式碼上。
     * 2. 這裡要重現的正是台股「季末日 vs PER 日期」的落差：序列的 dataAsOf 是
     *    2026-06-30，落地列的 data_as_of 是今天（PER 日期），兩者差 55 天。
     *    凍結後這個落差是常數，不會隨執行日縮放。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-24 09:00:00'));
    }

    /**
     * 造一份可評級的台股序列（落在規則 4 → B 級）。
     *
     * 開頭補一季 2025Q2：revenueYoy 是往前**正好四季**的同季基期查找
     * （OrderInventoryMetricsCalculator::calculate() 的 quarterAt(-4)），只有
     * 2026Q1／2026Q2 兩季時該基期查無此季，revenueYoy 恆為 null，C10
     * （比較同業營收年增中位數）永遠算不出來，與同業樣本是否可得無關。序列
     * 允許缺季（DTO 文件化的不變式），故只補這一季、不需要 2025Q3／2025Q4
     * 兩季連續。
     *
     * dataAsOf 是**最新季末日**（2026-06-30），與落地列的 data_as_of（台股是 PER
     * 日期，凍結後是 2026-08-24）刻意不同：同名不同語意正是修正 1 的坑，fixture
     * 若讓兩者相等，拿錯欄位的實作照樣全綠。
     */
    private function ratableSeries(): OrderInventoryData
    {
        return new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(period: '2025Q2', endDate: '2025-06-30', revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0),
                new QuarterlyFinancials(period: '2026Q1', endDate: '2026-03-31', revenue: 1000.0, costOfGoodsSold: 700.0, grossProfit: 300.0, inventories: 350.0),
                new QuarterlyFinancials(
                    period: '2026Q2',
                    endDate: '2026-06-30',
                    revenue: 1000.0, costOfGoodsSold: 700.0, grossProfit: 300.0, inventories: 350.0,
                ),
            ],
            market: 'tw',
            industry: '半導體業',
            dataAsOf: '2026-06-30',
        );
    }

    /**
     * 同一份序列，但標的營收年增為 1.0（2026Q2 是 2025Q2 的兩倍）。
     *
     * 這個數字刻意夾在同業中位數（0.50）與同業樣本數（5）之間：C10 是
     * 「標的年增 > 同業中位數」，1.0 > 0.50 成立、1.0 > 5.0 不成立，所以把
     * sampler 回傳陣列的 median 與 samples 兩個鍵接反時 C10 會翻面。
     * 兩季比例同步放大，毛利率與存貨天數維持不變，只有年增這一項改變。
     */
    private function seriesGrowingFasterThanPeers(): OrderInventoryData
    {
        return new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(period: '2025Q2', endDate: '2025-06-30', revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0),
                new QuarterlyFinancials(period: '2026Q1', endDate: '2026-03-31', revenue: 1500.0, costOfGoodsSold: 1050.0, grossProfit: 450.0, inventories: 525.0),
                new QuarterlyFinancials(
                    period: '2026Q2',
                    endDate: '2026-06-30',
                    revenue: 2000.0, costOfGoodsSold: 1400.0, grossProfit: 600.0, inventories: 700.0,
                ),
            ],
            market: 'tw',
            industry: '半導體業',
            dataAsOf: '2026-06-30',
        );
    }

    /**
     * 綁定兩個 provider 並回傳標的。
     *
     * $dataAsOf 是**落地列的資料日**（估值 provider 的 data_as_of，台股是 PER
     * 日期），不是序列的季末日；FundamentalsService 以它當 (instrument_id,
     * data_as_of) 的 upsert 鍵，因此它決定序列落在哪一列。前次評級是「資料日
     * 嚴格早於落地列」的那筆，所以每條測試都要明確指定這個日期，不能依賴
     * FakeFundamentalsProvider 寫死的 '2026-07-08'。
     */
    private function bindProvider(OrderInventoryData $data, ?string $dataAsOf = null): Instrument
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);
        $dataAsOf ??= now()->toDateString();

        $this->app->bind(CompanyFinancialsProvider::class, fn (): CompanyFinancialsProvider => new class($data) implements CompanyFinancialsProvider
        {
            public function __construct(private readonly OrderInventoryData $data) {}

            public function financials(string $symbol, int $months): OrderInventoryData
            {
                return $this->data;
            }
        });

        $this->app->bind(FundamentalsProvider::class, fn (): FundamentalsProvider => new class($dataAsOf) implements FundamentalsProvider
        {
            public function __construct(private readonly string $dataAsOf) {}

            public function fetch(string $symbol): FundamentalsData
            {
                // 只需要一項非 null 指標讓 FundamentalsService 判定抓取成功；
                // 估值數字本身與訂單／庫存評級無關。
                return new FundamentalsData(per: 18.5, dataAsOf: $this->dataAsOf);
            }
        });

        return $instrument;
    }

    /** 標的名下唯一那一列（明確取回，不依賴 get() 的未定義順序）。 */
    private function onlyRowOf(Instrument $instrument): Fundamental
    {
        return Fundamental::query()->where('instrument_id', $instrument->id)->sole();
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

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertNotNull($result);
        $this->assertSame(
            1,
            Fundamental::query()->where('instrument_id', $instrument->id)->count(),
            '寫回評級不得新增列——只更新 orderInventoryFor() 已落地的那一列',
        );
        $this->assertSame($result['assessment']->rating->value, $this->onlyRowOf($instrument)->order_inventory_rating);
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
        // 序列落地在今天那一列（PER 日期 2026-08-24），昨天那列留了一筆 C 級。
        // 序列自己的 dataAsOf 是季末日 2026-06-30，比昨天早了近兩個月：拿它當
        // 「本次」會把昨天那筆 C 一起排掉，ratingChange 於是錯報成 first。
        $instrument = $this->bindProvider($this->ratableSeries(), now()->toDateString());

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
    public function a_row_of_the_same_data_day_is_never_the_previous_rating(): void
    {
        // 今天的列已經落地過序列，也已經有評級（前一輪分析寫的）。
        $instrument = $this->bindProvider($this->ratableSeries(), now()->toDateString());

        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => now()->toDateString(),
            'fetched_at' => now(),
            'order_inventory' => $this->ratableSeries()->toArray(),
            'order_inventory_rating' => 'C',
        ]);

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertSame(
            'first',
            $result['assessment']->ratingChange,
            '同一資料日的自身列不是「前次」——資料日必須嚴格早於本次落地列',
        );
    }

    #[Test]
    public function it_never_reads_another_instruments_rating_as_the_previous_one(): void
    {
        $instrument = $this->bindProvider($this->ratableSeries(), now()->toDateString());

        // 另一檔股票昨天的評級。跨標的取前次評級會讓 ratingChange 講別人的故事。
        $other = Instrument::factory()->create(['symbol' => '2317.TW']);
        Fundamental::query()->create([
            'instrument_id' => $other->id,
            'data_as_of' => now()->subDay()->toDateString(),
            'fetched_at' => now()->subDay(),
            'order_inventory_rating' => 'C',
        ]);

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertSame(
            'first',
            $result['assessment']->ratingChange,
            '標的自己沒有更早的評級，前次評級就該是 null，不得撈到別檔的',
        );
    }

    #[Test]
    public function it_skips_a_previous_row_that_has_no_rating(): void
    {
        $instrument = $this->bindProvider($this->ratableSeries(), now()->toDateString());

        // 昨天只落地了序列、沒有評級（例如當天目標列是失敗列而未寫評級）。
        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => now()->subDay()->toDateString(),
            'fetched_at' => now()->subDay(),
        ]);

        // 前天才是最近一次真正有評級的資料日。
        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => now()->subDays(2)->toDateString(),
            'fetched_at' => now()->subDays(2),
            'order_inventory_rating' => 'C',
        ]);

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertSame(
            'upgraded',
            $result['assessment']->ratingChange,
            '要的是「最近一次有評級的資料日」，不是「最近一個資料日上的評級欄位」',
        );
    }

    #[Test]
    public function it_does_not_write_the_rating_onto_a_row_whose_last_touch_failed(): void
    {
        $instrument = $this->bindProvider($this->ratableSeries(), now()->toDateString());

        // 今天抓取失敗：FundamentalsService 保留這列的 last-known-good 序列、
        // 只刷新 failed_at。orderInventoryFor() 交出的是這列的舊序列。
        $row = Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => now()->toDateString(),
            'fetched_at' => now(),
            'failed_at' => now(),
            'order_inventory' => $this->ratableSeries()->toArray(),
            'order_inventory_rating' => 'C',
        ]);

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertNotNull($result);
        $this->assertNotSame(
            'C',
            $result['assessment']->rating->value,
            '本次算出的評級必須與該列原有的不同，否則這條測試證明不了什麼',
        );
        $this->assertSame(
            'C',
            $row->fresh()->order_inventory_rating,
            '失敗列端出的是舊序列，今天的評級不屬於這一列的觀測日，不得回頭覆寫歷史評級',
        );
    }

    #[Test]
    public function it_reports_no_peer_median_when_the_cache_is_empty(): void
    {
        $instrument = $this->bindProvider($this->ratableSeries());

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertArrayHasKey('peer_samples', $result);
        $this->assertNull(
            $result['assessment']->conditions['C10'],
            '沒有同業快取時中位數為 null，C10 必須是「不可評估」而不是壓成 false',
        );
    }

    #[Test]
    public function it_feeds_the_peer_median_into_the_assessment(): void
    {
        // 標的的列刻意落在較早的資料日，同業的列落在今天：少了 instrument_id
        // 限縮，「最新一列」就會挑到同業的列，評級被寫到別檔身上。
        $instrument = $this->bindProvider(
            $this->seriesGrowingFasterThanPeers(),
            now()->subDays(2)->toDateString(),
        );
        $floor = (int) config('order_inventory.peer.min_samples');

        // 造足量同業，年增全為 0.50，低於標的的 1.00（營收翻倍）。
        $peers = [];

        for ($i = 0; $i < $floor; $i++) {
            $peer = Instrument::factory()->create(['symbol' => "900{$i}.TW"]);
            $peers[] = $peer->id;
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
        $this->assertTrue(
            $result['assessment']->conditions['C10'],
            '標的年增 1.00 高於同業中位數 0.50——傳的必須是 median 而不是 samples',
        );

        $this->assertSame(
            $result['assessment']->rating->value,
            $this->onlyRowOf($instrument)->order_inventory_rating,
            '評級要落在標的自己那一列',
        );
        $this->assertSame(
            [],
            Fundamental::query()->whereIn('instrument_id', $peers)
                ->whereNotNull('order_inventory_rating')
                ->pluck('instrument_id')->all(),
            '同業的列不得被寫入標的的評級——批次掃描時整批評級會互相汙染',
        );
    }
}
