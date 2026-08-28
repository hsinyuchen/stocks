<?php

namespace Tests\Feature\Fundamentals;

use App\Contracts\FundamentalsProvider;
use App\Data\FundamentalsData;
use App\Data\OrderInventoryData;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\FundamentalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FundamentalsServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 新鮮度以「今天的資料公佈了沒」判斷（見 DailyDataFreshness），因此測試必須
     * 固定在公佈時刻之後，否則跨過午夜跑就會變成「今天還沒公佈、不必重抓」而失敗。
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-15 20:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** 計數 + 可回全 null 或拋例外的 stub。 */
    private function bindProvider(?FundamentalsData $data = null, bool $throw = false): object
    {
        $stub = new class($data, $throw) implements FundamentalsProvider
        {
            public int $calls = 0;

            public function __construct(private readonly ?FundamentalsData $data, private readonly bool $throw) {}

            public function fetch(string $symbol): FundamentalsData
            {
                $this->calls++;

                if ($this->throw) {
                    throw new \RuntimeException('finmind down');
                }

                return $this->data ?? new FundamentalsData;
            }
        };

        $this->app->instance(FundamentalsProvider::class, $stub);

        return $stub;
    }

    private function tw(): Instrument
    {
        return Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
    }

    public function test_non_taiwan_returns_null_without_calling_provider(): void
    {
        $stub = $this->bindProvider();
        $us = Instrument::factory()->create(['symbol' => 'NVDA', 'market' => 'US']);

        $this->assertNull(app(FundamentalsService::class)->forInstrument($us));
        $this->assertSame(0, $stub->calls);
        $this->assertSame(0, Fundamental::query()->count());
    }

    public function test_taiwan_with_no_row_fetches_and_persists(): void
    {
        $stub = $this->bindProvider(new FundamentalsData(per: 33.14, eps: 46.0));
        $instrument = $this->tw();

        $data = app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertSame(1, $stub->calls);
        $this->assertSame(33.14, $data->per);
        $this->assertSame(1, Fundamental::query()->where('instrument_id', $instrument->id)->count());
    }

    public function test_fresh_row_with_data_is_not_refetched(): void
    {
        $stub = $this->bindProvider(new FundamentalsData(per: 33.14));
        $instrument = $this->tw();
        app(FundamentalsService::class)->forInstrument($instrument);   // 1st fetch
        app(FundamentalsService::class)->forInstrument($instrument);   // fresh → no refetch

        $this->assertSame(1, $stub->calls);
    }

    public function test_stale_data_row_is_refetched(): void
    {
        $instrument = $this->tw();
        Fundamental::query()->create([
            'instrument_id' => $instrument->id, 'per' => 10.0,
            'fetched_at' => now()->subHours(25),   // 昨天抓的，在今日公佈時刻前 → 過期
        ]);
        $stub = $this->bindProvider(new FundamentalsData(per: 33.14));

        app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertSame(1, $stub->calls);
    }

    public function test_all_null_row_uses_short_failure_ttl(): void
    {
        $instrument = $this->tw();
        // 全 null 列（上次抓失敗），1 小時前 → 在 failure_ttl(2h) 內，不重抓
        Fundamental::query()->create(['instrument_id' => $instrument->id, 'fetched_at' => now()->subHour()]);
        $stub = $this->bindProvider(new FundamentalsData(per: 33.14));

        app(FundamentalsService::class)->forInstrument($instrument);
        $this->assertSame(0, $stub->calls);

        // 3 小時前 → 超過 failure_ttl，重抓
        Fundamental::query()->where('instrument_id', $instrument->id)->update(['fetched_at' => now()->subHours(3)]);
        app(FundamentalsService::class)->forInstrument($instrument);
        $this->assertSame(1, $stub->calls);
    }

    public function test_provider_failure_writes_null_row_and_does_not_throw(): void
    {
        $stub = $this->bindProvider(throw: true);
        $instrument = $this->tw();

        $data = app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertNull($data);   // 無既有非空資料 → null
        $this->assertSame(1, $stub->calls);
        // 寫了負快取列（fetched_at 更新），下次 failure_ttl 內不重打
        $this->assertSame(1, Fundamental::query()->where('instrument_id', $instrument->id)->count());
        app(FundamentalsService::class)->forInstrument($instrument);
        $this->assertSame(1, $stub->calls);   // 仍 1
    }

    /**
     * 抓取失敗（回全 null DTO，模擬 FinMind rate-limit/5xx，未拋例外）時，
     * 不得清空既有非空列，須保留 last-known-good，並回傳既有資料。
     */
    public function test_failed_refetch_preserves_last_known_good_metrics(): void
    {
        $instrument = $this->tw();
        Fundamental::query()->create([
            'instrument_id' => $instrument->id, 'per' => 33.14, 'eps' => 46.0,
            'fetched_at' => now()->subHours(25),   // 昨天抓的，在今日公佈時刻前 → 觸發重抓
        ]);
        // 回全 null DTO（rate-limit 路徑：provider->rows() 回 []，fetch() 不拋例外）
        $stub = $this->bindProvider(new FundamentalsData);

        $data = app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertSame(1, $stub->calls);
        $this->assertSame(33.14, $data->per);   // 回傳既有資料，非 null
        // DB 既有非空列未被清空
        $row = Fundamental::query()->where('instrument_id', $instrument->id)->first();
        $this->assertSame('33.1400', $row->per);
    }

    /**
     * 失敗後只刷新失敗標記：failure_ttl 內第二次不重打 provider；超過 failure_ttl 才重試。
     */
    public function test_failed_refetch_throttles_retry_within_failure_ttl(): void
    {
        $instrument = $this->tw();
        Fundamental::query()->create([
            'instrument_id' => $instrument->id, 'per' => 33.14,
            'fetched_at' => now()->subHours(25),
        ]);
        $stub = $this->bindProvider(new FundamentalsData);

        app(FundamentalsService::class)->forInstrument($instrument);   // 1st：失敗，保留舊值
        $this->assertSame(1, $stub->calls);

        // failure_ttl(2h) 內第二次 → 不重打
        app(FundamentalsService::class)->forInstrument($instrument);
        $this->assertSame(1, $stub->calls);

        // 讓 failed_at 超過 failure_ttl → 允許重試
        Fundamental::query()->where('instrument_id', $instrument->id)
            ->update(['failed_at' => now()->subHours(3)]);
        app(FundamentalsService::class)->forInstrument($instrument);
        $this->assertSame(2, $stub->calls);
    }

    public function test_fresh_monthly_revenue_is_kept_when_quarters_are_missing(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $previous = new OrderInventoryData(
            quarters: [],
            monthlyRevenue: [['month' => '2026-06-01', 'revenue' => 900.0, 'yoy' => 0.05]],
            market: 'tw',
        );
        $fresh = new OrderInventoryData(
            quarters: [],
            monthlyRevenue: [['month' => '2026-07-01', 'revenue' => 1000.0, 'yoy' => 0.10]],
            market: 'tw',
        );

        // 專案沒有 FundamentalFactory，直接建列。fetched_at 是必填（migration 未設 nullable）。
        $row = Fundamental::create([
            'instrument_id' => $instrument->id,
            'order_inventory' => $previous->toArray(),
            'data_as_of' => '2026-07-01',
            'fetched_at' => now(),
        ]);

        $result = $this->invokeCarryForward($fresh, $row);

        // 舊行為會回 $previous，讓新抓到的 7 月營收無聲消失。
        $this->assertSame('2026-07-01', $result->monthlyRevenue[0]['month']);
    }

    private function invokeCarryForward(OrderInventoryData $fresh, Fundamental $row): ?OrderInventoryData
    {
        $method = new \ReflectionMethod(FundamentalsService::class, 'carryForwardOrderInventory');
        $method->setAccessible(true);

        return $method->invoke(app(FundamentalsService::class), $fresh, $row);
    }

    public function test_payload_numbers_are_floats_not_strings(): void
    {
        $this->bindProvider(new FundamentalsData(per: 33.14, eps: 46.0));
        $data = app(FundamentalsService::class)->forInstrument($this->tw());

        $this->assertIsFloat($data->per);
        $this->assertIsFloat($data->eps);
    }
}
