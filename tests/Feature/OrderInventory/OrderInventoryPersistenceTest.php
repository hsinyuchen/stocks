<?php

namespace Tests\Feature\OrderInventory;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FundamentalsProvider;
use App\Data\FundamentalsData;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\FundamentalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OrderInventoryPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_order_inventory_is_persisted_and_read_back(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $data = app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertNotNull($data);
        $this->assertNotNull($data->orderInventory, '財報序列應隨基本面一併取得');
        $this->assertTrue($data->orderInventory->hasAny());

        // 落地為 JSON 欄位而非十幾個純量欄位。
        $row = Fundamental::query()->where('instrument_id', $instrument->id)->first();
        $this->assertNotNull($row->order_inventory);
        $this->assertIsArray($row->order_inventory);
    }

    public function test_second_call_reads_from_cache_without_refetching(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);
        $service = app(FundamentalsService::class);

        $first = $service->forInstrument($instrument);
        $second = $service->forInstrument($instrument);

        $this->assertSame(
            $first->orderInventory->latestQuarter()->period,
            $second->orderInventory->latestQuarter()->period,
        );
        $this->assertSame(1, Fundamental::query()->where('instrument_id', $instrument->id)->count());
    }

    public function test_round_trip_preserves_quarter_values_and_nulls(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        Fundamental::query()->updateOrCreate(
            ['instrument_id' => $instrument->id, 'data_as_of' => '2026-06-30'],
            [
                'order_inventory' => (new OrderInventoryData(
                    quarters: [new QuarterlyFinancials(period: '2026Q2', inventories: 600.0)],
                    market: 'tw',
                ))->toArray(),
                'fetched_at' => now(),
            ],
        );

        $row = Fundamental::query()->where('instrument_id', $instrument->id)->first();
        $restored = OrderInventoryData::fromArray($row->order_inventory);

        $this->assertSame(600.0, $restored->latestQuarter()->inventories);
        $this->assertNull($restored->latestQuarter()->revenue);
        $this->assertSame('tw', $restored->market);
    }

    public function test_existing_valuation_fields_are_unaffected(): void
    {
        // 迴歸：新欄位不得影響既有估值路徑。
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $data = app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertNotNull($data->per);
        $this->assertNotNull($data->dataAsOf);
    }

    /** 既有列的 order_inventory（完整一包），供「部分上游失敗」情境當 last-known-good。 */
    private function seedStoredOrderInventory(Instrument $instrument): OrderInventoryData
    {
        $stored = new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(period: '2026Q1', endDate: '2026-03-31', revenue: 900.0, inventories: 500.0),
                new QuarterlyFinancials(period: '2026Q2', endDate: '2026-06-30', revenue: 1000.0, inventories: 600.0),
            ],
            monthlyRevenue: [
                ['month' => '2026-05-01', 'revenue' => 100.0, 'yoy' => 0.1],
                ['month' => '2026-06-01', 'revenue' => 120.0, 'yoy' => 0.2],
            ],
            market: 'tw',
            industry: '光電業',
            dataAsOf: '2026-06-30',
        );

        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'per' => 33.14,
            'data_as_of' => '2026-07-14',
            'fetched_at' => now()->subHours(25),   // 昨天抓的 → 今日公佈時刻後視為過期，觸發重抓
            'order_inventory' => $stored->toArray(),
        ]);

        return $stored;
    }

    /** 估值 provider 正常回值（模擬「PER 已到手、後續 dataset 才撞額度」）。 */
    private function bindValuationProvider(): void
    {
        $this->app->instance(FundamentalsProvider::class, new class implements FundamentalsProvider
        {
            public function fetch(string $symbol): FundamentalsData
            {
                return new FundamentalsData(per: 34.0, dataAsOf: '2026-07-14');
            }
        });
    }

    private function bindFinancialsProvider(OrderInventoryData $data): void
    {
        $this->app->instance(CompanyFinancialsProvider::class, new class($data) implements CompanyFinancialsProvider
        {
            public function __construct(private readonly OrderInventoryData $data) {}

            public function financials(string $symbol, int $months): OrderInventoryData
            {
                return $this->data;
            }
        });
    }

    public function test_partial_upstream_failure_does_not_wipe_stored_order_inventory(): void
    {
        // FinMindGate 在估值抓完後才跳閘 → financials() 全部短路回空。
        // 抓不到不等於沒有，既有觀測值不得被 null 覆蓋。
        Carbon::setTestNow(Carbon::parse('2026-07-15 20:00', 'Asia/Taipei'));
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
        $stored = $this->seedStoredOrderInventory($instrument);
        $this->bindValuationProvider();
        $this->bindFinancialsProvider(OrderInventoryData::empty());

        $data = app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertNotNull($data->orderInventory, '抓不到時應沿用既有序列，不得回 null');
        $this->assertSame('2026Q2', $data->orderInventory->latestQuarter()->period);
        $this->assertSame(600.0, $data->orderInventory->latestQuarter()->inventories);

        // JSON 欄位往返後浮點會退化成整數（json_encode/decode 既有行為，與本次修正無關），
        // 故用寬鬆比較。
        $row = Fundamental::query()->where('instrument_id', $instrument->id)->first();
        $this->assertEquals($stored->toArray(), $row->order_inventory, 'DB 既有 order_inventory 不得被覆蓋');
    }

    public function test_empty_monthly_revenue_does_not_wipe_stored_series(): void
    {
        // 只有 TaiwanStockMonthRevenue 失敗：季度序列有值 → hasAny() 為 true，
        // 但月營收會以 [] 蓋掉既有序列。
        Carbon::setTestNow(Carbon::parse('2026-07-15 20:00', 'Asia/Taipei'));
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
        $stored = $this->seedStoredOrderInventory($instrument);
        $this->bindValuationProvider();
        $this->bindFinancialsProvider(new OrderInventoryData(
            quarters: [new QuarterlyFinancials(period: '2026Q3', endDate: '2026-09-30', revenue: 1100.0, inventories: 650.0)],
            monthlyRevenue: [],
            market: 'tw',
            industry: '光電業',
            dataAsOf: '2026-09-30',
        ));

        $data = app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertSame('2026Q3', $data->orderInventory->latestQuarter()->period, '新的季度序列應生效');
        $this->assertEquals($stored->monthlyRevenue, $data->orderInventory->monthlyRevenue, '舊的月營收序列應保留');

        $row = Fundamental::query()->where('instrument_id', $instrument->id)->first();
        $this->assertEquals($stored->monthlyRevenue, $row->order_inventory['monthly_revenue']);
    }

    public function test_negative_cache_cleanup_does_not_delete_rows_carrying_a_series(): void
    {
        // handleFailure() 會刪掉「所有估值欄位皆 null」的列來清負快取。帶著
        // order_inventory 的列符合這個條件，但它是觀測值、不是重試節流的殘留，
        // 任何情況下都不能被當成負快取清掉。美股列每一列都長這樣。
        Carbon::setTestNow(Carbon::parse('2026-07-15 20:00', 'Asia/Taipei'));
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $stored = new OrderInventoryData(
            quarters: [new QuarterlyFinancials(period: '2026Q2', endDate: '2026-06-30', inventories: 600.0)],
            market: 'tw',
            dataAsOf: '2026-06-30',
        );

        $kept = Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => '2026-06-30',
            'fetched_at' => now()->subHours(5),   // 超過 failure_ttl → 視為過期，觸發重抓
            'order_inventory' => $stored->toArray(),
        ]);

        // 估值抓取失敗（全 null DTO）→ 走 handleFailure() 的負快取清理分支。
        $this->app->instance(FundamentalsProvider::class, new class implements FundamentalsProvider
        {
            public function fetch(string $symbol): FundamentalsData
            {
                return new FundamentalsData;
            }
        });

        $this->assertNull(app(FundamentalsService::class)->forInstrument($instrument), '契約不變：失敗仍回 null');

        $survivor = Fundamental::query()->find($kept->id);

        $this->assertNotNull($survivor, '帶序列的列不得被負快取清理刪掉');
        $this->assertEquals($stored->toArray(), $survivor->order_inventory);
    }
}
