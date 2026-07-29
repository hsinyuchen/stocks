<?php

namespace Tests\Feature\Fundamentals;

use App\Contracts\FundamentalsProvider;
use App\Data\FundamentalsData;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\FundamentalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FundamentalsHistoryTest extends TestCase
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

    private function bindProvider(FundamentalsData $data): void
    {
        $this->app->instance(FundamentalsProvider::class, new class($data) implements FundamentalsProvider
        {
            public function __construct(private readonly FundamentalsData $data) {}

            public function fetch(string $symbol): FundamentalsData
            {
                return $this->data;
            }
        });
    }

    private function tw(): Instrument
    {
        return Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
    }

    /** @param list<array{0: string, 1: float}> $rows date => per */
    private function seedHistory(Instrument $instrument, array $rows): void
    {
        foreach ($rows as [$date, $per]) {
            Fundamental::query()->create([
                'instrument_id' => $instrument->id,
                'per' => $per,
                'pbr' => $per / 3,
                'data_as_of' => $date,
                'fetched_at' => now(),
            ]);
        }
    }

    public function test_different_data_dates_accumulate_instead_of_overwriting(): void
    {
        $instrument = $this->tw();

        $this->seedHistory($instrument, [['2026-07-01', 30.0], ['2026-07-02', 31.0]]);

        $this->assertSame(2, Fundamental::query()->where('instrument_id', $instrument->id)->count());
    }

    /** 同一資料日重抓仍是就地更新，不得產生重複列。 */
    public function test_same_data_date_is_updated_in_place(): void
    {
        $instrument = $this->tw();
        $this->bindProvider(new FundamentalsData(per: 30.0, dataAsOf: '2026-07-01'));

        app(FundamentalsService::class)->forInstrument($instrument);

        Fundamental::query()->update(['fetched_at' => now()->subDays(5)]);   // 讓快取過期
        $this->bindProvider(new FundamentalsData(per: 35.0, dataAsOf: '2026-07-01'));
        app(FundamentalsService::class)->forInstrument($instrument);

        $rows = Fundamental::query()->where('instrument_id', $instrument->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(35.0, (float) $rows[0]->per);
    }

    /** 新鮮度看的是最新一筆，不能被較舊的歷史列誤判成 fresh。 */
    public function test_freshness_uses_the_latest_row(): void
    {
        $instrument = $this->tw();
        $this->seedHistory($instrument, [['2026-07-01', 30.0]]);
        $this->seedHistory($instrument, [['2026-07-02', 40.0]]);

        $data = app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertSame(40.0, $data->per);
        $this->assertSame('2026-07-02', $data->dataAsOf);
    }

    public function test_percentile_is_null_when_samples_are_insufficient(): void
    {
        config(['fundamentals.percentile_min_samples' => 20]);
        $instrument = $this->tw();
        $this->seedHistory($instrument, [['2026-07-01', 30.0], ['2026-07-02', 31.0]]);

        $this->assertNull(app(FundamentalsService::class)->valuationPercentiles($instrument));
    }

    public function test_percentile_reports_position_within_own_history(): void
    {
        config(['fundamentals.percentile_min_samples' => 5]);
        $instrument = $this->tw();

        // 10, 20, 30, 40, 50 —— 現值 50 為歷史最高，分位 100%。
        $this->seedHistory($instrument, [
            ['2026-07-01', 10.0], ['2026-07-02', 20.0], ['2026-07-03', 30.0],
            ['2026-07-04', 40.0], ['2026-07-05', 50.0],
        ]);

        $p = app(FundamentalsService::class)->valuationPercentiles($instrument)['per'];

        $this->assertSame(50.0, $p['value']);
        $this->assertSame(100.0, $p['percentile']);
        $this->assertSame(10.0, $p['min']);
        $this->assertSame(30.0, $p['median']);
        $this->assertSame(50.0, $p['max']);
        $this->assertSame(5, $p['samples']);
    }

    /** 現值取「資料日最新」而非資料庫最後寫入，亂序寫入不得影響結果。 */
    public function test_current_value_follows_data_date_order(): void
    {
        config(['fundamentals.percentile_min_samples' => 3]);
        $instrument = $this->tw();

        $this->seedHistory($instrument, [['2026-07-03', 15.0], ['2026-07-01', 60.0], ['2026-07-02', 45.0]]);

        $p = app(FundamentalsService::class)->valuationPercentiles($instrument)['per'];

        $this->assertSame(15.0, $p['value'], '最新資料日是 07-03。');
        $this->assertEqualsWithDelta(33.3, $p['percentile'], 0.1);
    }

    /** 非正值（0 或缺漏）不得混進分位母體，否則會把「無資料」當成極便宜。 */
    public function test_non_positive_values_are_excluded(): void
    {
        config(['fundamentals.percentile_min_samples' => 3]);
        $instrument = $this->tw();

        $this->seedHistory($instrument, [['2026-07-01', 10.0], ['2026-07-02', 20.0], ['2026-07-03', 30.0]]);
        Fundamental::query()->create([
            'instrument_id' => $instrument->id, 'per' => 0, 'pbr' => 0,
            'data_as_of' => '2026-06-30', 'fetched_at' => now(),
        ]);

        $p = app(FundamentalsService::class)->valuationPercentiles($instrument)['per'];

        $this->assertSame(3, $p['samples']);
        $this->assertSame(10.0, $p['min']);
    }

    /** 負快取列是重試節流，不是觀測值；連續失敗不得每天堆一列空資料。 */
    public function test_failure_rows_do_not_accumulate(): void
    {
        $instrument = $this->tw();
        $this->bindProvider(new FundamentalsData);

        app(FundamentalsService::class)->forInstrument($instrument);
        Fundamental::query()->update(['fetched_at' => now()->subDays(5), 'data_as_of' => '2026-07-01']);
        app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertSame(1, Fundamental::query()->where('instrument_id', $instrument->id)->count());
    }

    /** 失敗不得刪掉有指標的歷史列（last-known-good 必須保留）。 */
    public function test_failure_keeps_rows_that_have_metrics(): void
    {
        $instrument = $this->tw();
        $this->seedHistory($instrument, [['2026-07-01', 30.0], ['2026-07-02', 31.0]]);
        Fundamental::query()->update(['fetched_at' => now()->subDays(5)]);
        $this->bindProvider(new FundamentalsData);

        $data = app(FundamentalsService::class)->forInstrument($instrument);

        $this->assertSame(2, Fundamental::query()->where('instrument_id', $instrument->id)->count());
        $this->assertSame(31.0, $data->per, '應回傳 last-known-good。');
    }
}
