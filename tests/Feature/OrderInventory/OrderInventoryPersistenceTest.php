<?php

namespace Tests\Feature\OrderInventory;

use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\FundamentalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
