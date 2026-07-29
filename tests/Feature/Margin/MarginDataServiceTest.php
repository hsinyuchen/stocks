<?php

namespace Tests\Feature\Margin;

use App\Contracts\MarginDataProvider;
use App\Data\MarginFlowData;
use App\Models\Instrument;
use App\Models\MarginFlow;
use App\Services\Margin\MarginDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarginDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_us_symbols_return_nothing(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL', 'market' => 'US']);

        // 美股沒有個股融資餘額的公開資料（FINRA 只發布全市場 margin debt 月報）。
        $this->assertSame([], app(MarginDataService::class)->forInstrument($instrument));
        $this->assertSame(0, MarginFlow::query()->count());
    }

    public function test_it_caches_rows_and_serves_from_cache(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $rows = app(MarginDataService::class)->forInstrument($instrument);

        $this->assertNotSame([], $rows);
        $this->assertSame(count($rows), MarginFlow::query()->count());
        $this->assertContainsOnlyInstancesOf(MarginFlowData::class, $rows);

        // 第二次不該再打上游——換一個會拋例外的 provider，仍要能回快取內容。
        $this->app->bind(MarginDataProvider::class, fn () => new class implements MarginDataProvider
        {
            public function fetch(string $symbol, int $days): array
            {
                throw new \RuntimeException('不該被呼叫');
            }
        });

        $this->assertCount(count($rows), app(MarginDataService::class)->forInstrument($instrument));
    }

    public function test_upstream_failure_keeps_last_known_good(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $before = app(MarginDataService::class)->forInstrument($instrument);
        $this->assertNotSame([], $before);

        // 讓快取過期，並讓上游回空（FinMind 達到流量上限時的行為）。
        MarginFlow::query()->update(['updated_at' => now()->subDays(2)]);
        Cache::flush();
        $this->app->bind(MarginDataProvider::class, fn () => new class implements MarginDataProvider
        {
            public function fetch(string $symbol, int $days): array
            {
                return [];
            }
        });

        $after = app(MarginDataService::class)->forInstrument($instrument);

        // 空回應視為失敗：保留既有資料，不清空 last-known-good。
        $this->assertCount(count($before), $after);
    }

    public function test_failure_is_throttled_so_the_next_request_does_not_retry(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $calls = 0;
        $this->app->bind(MarginDataProvider::class, function () use (&$calls) {
            return new class($calls) implements MarginDataProvider
            {
                public function __construct(private int &$calls) {}

                public function fetch(string $symbol, int $days): array
                {
                    $this->calls++;

                    return [];
                }
            };
        });

        app(MarginDataService::class)->forInstrument($instrument);
        app(MarginDataService::class)->forInstrument($instrument);

        // 第二次應被節流擋下，不對已達流量上限的上游重打。
        $this->assertSame(1, $calls);
    }
}
