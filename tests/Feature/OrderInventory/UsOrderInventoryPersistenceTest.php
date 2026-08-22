<?php

namespace Tests\Feature\OrderInventory;

use App\Contracts\CompanyFinancialsProvider;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\FundamentalsService;
use App\Services\Fundamentals\SecEdgarFinancialsProvider;
use App\Services\Fundamentals\SecTickerCikResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 美股序列的落地路徑：orderInventoryFor() 的美股分支。
 *
 * phpunit.xml 把 MARKET_DATA_DRIVER 鎖成 fake，容器綁的是 FakeCompanyFinancialsProvider，
 * 量不到真實的 SEC 行為；故本檔一律自行把 CompanyFinancialsProvider 綁成真的
 * SecEdgarFinancialsProvider，並用 Http::fake() 攔在 HTTP 層（不會真的出網）。
 */
class UsOrderInventoryPersistenceTest extends TestCase
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

    /**
     * 把 CompanyFinancialsProvider 換成真的 SEC provider，並 stub 兩個 SEC 端點。
     *
     * @param  array<string, array<string, float|int>>  $tags  us-gaap 標籤 => [frame => val]
     */
    private function fakeSec(array $tags, string $endDate = '2026-06-30'): void
    {
        // Http::fake() 是疊加而非取代，同一測試方法內換 stub 必須先重置解析實例，
        // 否則舊 stub 仍會先命中同一 URL pattern。
        Http::clearResolvedInstance(HttpFactory::class);
        $this->app->forgetInstance(HttpFactory::class);

        $facts = [];

        foreach ($tags as $tag => $frames) {
            $units = [];
            foreach ($frames as $frame => $val) {
                $units[] = ['val' => $val, 'end' => $endDate, 'form' => '10-Q', 'frame' => $frame];
            }
            $facts[$tag] = ['units' => ['USD' => $units]];
        }

        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                '0' => ['cik_str' => 1045810, 'ticker' => 'NVDA', 'title' => 'NVIDIA CORP'],
            ], 200),
            'data.sec.gov/api/xbrl/companyfacts/*' => Http::response(['facts' => ['us-gaap' => $facts]], 200),
        ]);

        $this->app->instance(
            CompanyFinancialsProvider::class,
            new SecEdgarFinancialsProvider(new SecTickerCikResolver),
        );
    }

    private function companyFactsCallCount(): int
    {
        $count = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'data.sec.gov/api/xbrl/companyfacts/')) {
                $count++;
            }
        }

        return $count;
    }

    private function usInstrument(): Instrument
    {
        return Instrument::factory()->create(['symbol' => 'NVDA', 'market' => 'US']);
    }

    public function test_first_call_fetches_sec_and_persists_series_without_valuation_fields(): void
    {
        $this->fakeSec([
            'InventoryNet' => ['CY2026Q1I' => 100, 'CY2026Q2I' => 120],
            'CostOfRevenue' => ['CY2026Q1' => 70, 'CY2026Q2' => 80],
            'RevenueFromContractWithCustomerExcludingAssessedTax' => ['CY2026Q1' => 100, 'CY2026Q2' => 110],
        ]);
        $instrument = $this->usInstrument();

        $data = app(FundamentalsService::class)->orderInventoryFor($instrument);

        $this->assertNotNull($data);
        $this->assertSame('us', $data->market);
        $this->assertSame('2026Q2', $data->latestQuarter()->period);
        $this->assertSame(1, $this->companyFactsCallCount(), '第一次呼叫應打 SEC 一次');

        $rows = Fundamental::query()->where('instrument_id', $instrument->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();

        $this->assertIsArray($row->order_inventory);
        $this->assertSame('2026Q2', $row->order_inventory['quarters'][1]['period']);
        // data_as_of 在美股是最新季末日（DTO 的 dataAsOf），不是台股那種 PER 日期。
        $this->assertSame('2026-06-30', $row->data_as_of->toDateString());
        $this->assertNotNull($row->fetched_at);
        $this->assertNull($row->failed_at);

        foreach (Fundamental::METRIC_COLUMNS as $column) {
            $this->assertNull($row->{$column}, "美股列不得寫入估值欄位 {$column}");
        }
    }

    public function test_second_call_within_ttl_does_not_hit_sec_again(): void
    {
        $this->fakeSec([
            'InventoryNet' => ['CY2026Q2I' => 120],
            'CostOfRevenue' => ['CY2026Q2' => 80],
        ]);
        $instrument = $this->usInstrument();

        $first = app(FundamentalsService::class)->orderInventoryFor($instrument);
        $second = app(FundamentalsService::class)->orderInventoryFor($instrument);

        $this->assertSame(1, $this->companyFactsCallCount(), 'TTL 內第二次呼叫不得再打 SEC');
        $this->assertEquals($first->toArray(), $second->toArray());
        $this->assertSame(1, Fundamental::query()->where('instrument_id', $instrument->id)->count());
    }

    public function test_expired_row_refetches_when_ttl_has_passed(): void
    {
        // 反向控制：證明上一個測試的「不再打 SEC」是 TTL 生效，不是路徑根本不會抓。
        $this->fakeSec([
            'InventoryNet' => ['CY2026Q2I' => 120],
            'CostOfRevenue' => ['CY2026Q2' => 80],
        ]);
        $instrument = $this->usInstrument();

        Carbon::setTestNow(Carbon::parse('2026-08-20 09:00'));
        app(FundamentalsService::class)->orderInventoryFor($instrument);

        Carbon::setTestNow(Carbon::parse('2026-08-22 09:00'));
        app(FundamentalsService::class)->orderInventoryFor($instrument);

        $this->assertSame(2, $this->companyFactsCallCount(), 'TTL 過期後應重抓');
    }

    public function test_empty_upstream_keeps_stored_series_and_only_marks_failure(): void
    {
        // 上游回空（SEC 429/故障，或 ticker 查不到）不等於「這家公司沒財報」。
        // 既有觀測值不得被清掉，也不得因此多長出一列空資料。
        Carbon::setTestNow(Carbon::parse('2026-08-22 09:00'));
        $instrument = $this->usInstrument();

        $stored = new OrderInventoryData(
            quarters: [new QuarterlyFinancials(period: '2026Q2', endDate: '2026-06-30', revenue: 1000.0, inventories: 600.0)],
            market: 'us',
            inventoryCompositionAvailable: true,
            dataAsOf: '2026-06-30',
        );

        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => '2026-06-30',
            'fetched_at' => now()->subDays(3),     // 遠超過 us_ttl_hours → 會重抓
            'order_inventory' => $stored->toArray(),
        ]);

        // 429：SEC 暫時性拒絕 → provider 回 empty()。
        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                '0' => ['cik_str' => 1045810, 'ticker' => 'NVDA'],
            ], 200),
            'data.sec.gov/*' => Http::response('', 429),
        ]);
        $this->app->instance(
            CompanyFinancialsProvider::class,
            new SecEdgarFinancialsProvider(new SecTickerCikResolver),
        );

        $data = app(FundamentalsService::class)->orderInventoryFor($instrument);

        $this->assertNotNull($data, '抓不到時應沿用既有序列，不得回 null');
        $this->assertSame('2026Q2', $data->latestQuarter()->period);

        $rows = Fundamental::query()->where('instrument_id', $instrument->id)->get();
        $this->assertCount(1, $rows, '失敗不得新增一列空資料');

        $row = $rows->first();
        $this->assertEquals($stored->toArray(), $row->order_inventory, '既有序列不得被覆蓋');
        $this->assertNotNull($row->failed_at, 'failed_at 應被更新以節流重試');
        $this->assertSame('2026-06-30', $row->data_as_of->toDateString());
    }

    public function test_failure_throttles_subsequent_calls(): void
    {
        // 失敗後在 failure_ttl 內不得對 SEC 連續重打。
        Carbon::setTestNow(Carbon::parse('2026-08-22 09:00'));
        $instrument = $this->usInstrument();

        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                '0' => ['cik_str' => 1045810, 'ticker' => 'NVDA'],
            ], 200),
            'data.sec.gov/*' => Http::response('', 429),
        ]);
        $this->app->instance(
            CompanyFinancialsProvider::class,
            new SecEdgarFinancialsProvider(new SecTickerCikResolver),
        );

        $service = app(FundamentalsService::class);
        $this->assertNull($service->orderInventoryFor($instrument));
        $this->assertNull($service->orderInventoryFor($instrument));

        $this->assertSame(1, $this->companyFactsCallCount(), 'failure_ttl 內不得重打 SEC');
    }

    public function test_valuation_percentiles_ignore_us_rows(): void
    {
        // 美股列的 per/pbr 為 null，本來就被分位統計濾掉；釘住這件事，
        // 避免日後有人「順手」把美股列也算進分位而產生看似精確的假數字。
        $instrument = $this->usInstrument();
        $minSamples = (int) config('fundamentals.percentile_min_samples', 20);

        for ($i = 0; $i < $minSamples + 5; $i++) {
            Fundamental::query()->create([
                'instrument_id' => $instrument->id,
                'data_as_of' => Carbon::parse('2026-01-01')->addDays($i),
                'fetched_at' => now(),
                'order_inventory' => (new OrderInventoryData(
                    quarters: [new QuarterlyFinancials(period: '2026Q2', inventories: 600.0)],
                    market: 'us',
                ))->toArray(),
            ]);
        }

        $this->assertNull(
            app(FundamentalsService::class)->valuationPercentiles($instrument),
            '美股列不得產生估值分位',
        );

        // 對照組：同樣筆數但有 per 時分位算得出來，證明上面的 null 不是樣本數不足以外的原因。
        $taiwan = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        for ($i = 0; $i < $minSamples; $i++) {
            Fundamental::query()->create([
                'instrument_id' => $taiwan->id,
                'data_as_of' => Carbon::parse('2026-01-01')->addDays($i),
                'fetched_at' => now(),
                'per' => 10 + $i,
            ]);
        }

        $this->assertNotNull(app(FundamentalsService::class)->valuationPercentiles($taiwan));
    }
}
