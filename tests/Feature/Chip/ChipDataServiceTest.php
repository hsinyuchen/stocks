<?php

namespace Tests\Feature\Chip;

use App\Contracts\ChipDataProvider;
use App\Data\ChipFlowData;
use App\Models\ChipFlow;
use App\Models\Instrument;
use App\Services\Chip\ChipDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChipDataServiceTest extends TestCase
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

    private function service(ChipDataProvider $provider): ChipDataService
    {
        return new ChipDataService($provider);
    }

    private function taiwanInstrument(): Instrument
    {
        return Instrument::factory()->create(['symbol' => '2330.TW']);
    }

    public function test_fetches_and_persists_then_serves_from_cache(): void
    {
        $provider = new CountingChipProvider;
        $instrument = $this->taiwanInstrument();

        $first = $this->service($provider)->forInstrument($instrument, 30);

        $this->assertCount(3, $first);
        $this->assertSame(1, $provider->calls);
        $this->assertSame(3, ChipFlow::query()->count());

        $second = $this->service($provider)->forInstrument($instrument, 30);

        $this->assertCount(3, $second);
        $this->assertSame(1, $provider->calls, '新鮮快取不得重打上游。');
    }

    public function test_returns_rows_sorted_ascending_by_date(): void
    {
        $instrument = $this->taiwanInstrument();

        $rows = $this->service(new CountingChipProvider)->forInstrument($instrument, 30);

        $this->assertSame(['2026-07-20', '2026-07-21', '2026-07-22'], array_column($rows, 'date'));
    }

    /** 非台股不呼叫上游，直接回空——籌碼資料僅台股有。 */
    public function test_non_taiwan_symbol_returns_empty_without_calling_upstream(): void
    {
        $provider = new CountingChipProvider;
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);

        $this->assertSame([], $this->service($provider)->forInstrument($instrument, 30));
        $this->assertSame(0, $provider->calls);
    }

    /** 上游拋例外不得往上冒泡，且必須保留既有快取（last-known-good）。 */
    public function test_provider_exception_preserves_existing_cache_and_does_not_throw(): void
    {
        $instrument = $this->taiwanInstrument();
        $this->service(new CountingChipProvider)->forInstrument($instrument, 30);

        // 清掉失敗節流與新鮮度，強制下一次重抓。
        ChipFlow::query()->update(['updated_at' => now()->subDays(5)]);

        $rows = $this->service(new ThrowingChipProvider)->forInstrument($instrument, 30);

        $this->assertCount(3, $rows, '抓取失敗應回既有快取，而非清空。');
        $this->assertSame(3, ChipFlow::query()->count());
    }

    /** 空回應（FinMind rate-limit 時 provider 回 []）與例外同樣視為失敗。 */
    public function test_empty_upstream_response_preserves_existing_cache(): void
    {
        $instrument = $this->taiwanInstrument();
        $this->service(new CountingChipProvider)->forInstrument($instrument, 30);

        ChipFlow::query()->update(['updated_at' => now()->subDays(5)]);

        $rows = $this->service(new EmptyChipProvider)->forInstrument($instrument, 30);

        $this->assertCount(3, $rows);
    }

    /** 失敗後在 failure TTL 內不得重打上游。 */
    public function test_failure_is_throttled_within_failure_ttl(): void
    {
        config(['chip.failure_ttl_hours' => 2]);

        $instrument = $this->taiwanInstrument();
        $provider = new EmptyChipProvider;

        $this->service($provider)->forInstrument($instrument, 30);
        $this->service($provider)->forInstrument($instrument, 30);
        $this->service($provider)->forInstrument($instrument, 30);

        $this->assertSame(1, $provider->calls, '失敗後應節流，不得每次開頁重打。');
    }

    /** TTL 過期後應重新抓取並就地更新，不產生重複列。 */
    public function test_stale_cache_refetches_and_updates_in_place(): void
    {
        config(['chip.ttl_hours' => 12]);

        $instrument = $this->taiwanInstrument();
        $provider = new CountingChipProvider;

        $this->service($provider)->forInstrument($instrument, 30);
        ChipFlow::query()->update(['updated_at' => now()->subHours(13)]);

        $this->service($provider)->forInstrument($instrument, 30);

        $this->assertSame(2, $provider->calls);
        $this->assertSame(3, ChipFlow::query()->count(), 'unique(instrument_id, traded_at) 應就地更新。');
    }

    public function test_days_limits_returned_rows_to_the_most_recent(): void
    {
        $instrument = $this->taiwanInstrument();
        $this->service(new CountingChipProvider)->forInstrument($instrument, 30);

        $rows = $this->service(new CountingChipProvider)->forInstrument($instrument, 2);

        $this->assertCount(2, $rows);
        $this->assertSame(['2026-07-21', '2026-07-22'], array_column($rows, 'date'));
    }

    /** 全站共用資料：instrument 刪除時連帶清除。 */
    public function test_chip_rows_are_removed_with_the_instrument(): void
    {
        $instrument = $this->taiwanInstrument();
        $this->service(new CountingChipProvider)->forInstrument($instrument, 30);

        $instrument->delete();

        $this->assertSame(0, ChipFlow::query()->count());
    }
}

final class CountingChipProvider implements ChipDataProvider
{
    public int $calls = 0;

    public function fetch(string $symbol, int $days): array
    {
        $this->calls++;

        return [
            new ChipFlowData('2026-07-20', -1_672_128, 1_048_697, 4_625_837, 4_002_406),
            new ChipFlowData('2026-07-21', 4_975_729, 973_707, 213_814, 6_163_250),
            new ChipFlowData('2026-07-22', 1_000_000, 200_000, -50_000, 1_150_000),
        ];
    }
}

final class ThrowingChipProvider implements ChipDataProvider
{
    public function fetch(string $symbol, int $days): array
    {
        throw new \RuntimeException('upstream exploded');
    }
}

final class EmptyChipProvider implements ChipDataProvider
{
    public int $calls = 0;

    public function fetch(string $symbol, int $days): array
    {
        $this->calls++;

        return [];
    }
}
