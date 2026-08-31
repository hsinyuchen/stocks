<?php

namespace Tests\Feature\FinancialStatements;

use App\Contracts\FinancialStatementSource;
use App\Data\FetchResult;
use App\Data\PeriodFactSet;
use App\Enums\FetchStatus;
use App\Services\FinancialStatements\CachedFinancialStatementSource;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CachedFinancialStatementSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function inner(FetchResult $result, ?int &$calls = null): FinancialStatementSource
    {
        return new class($result, $calls) implements FinancialStatementSource
        {
            public function __construct(private FetchResult $result, public ?int &$calls) {}

            public function fetch(string $symbol, int $quarters, int $years): FetchResult
            {
                $this->calls = ($this->calls ?? 0) + 1;

                return $this->result;
            }
        };
    }

    public function test_complete_result_is_cached(): void
    {
        $calls = 0;
        $result = new FetchResult(FetchStatus::Complete, new PeriodFactSet([], 'us'));
        $source = new CachedFinancialStatementSource($this->inner($result, $calls));

        $source->fetch('NVDA', 12, 5);
        $source->fetch('NVDA', 12, 5);

        $this->assertSame(1, $calls, '第二次應該命中快取');
    }

    public function test_partial_result_is_never_cached(): void
    {
        // 前兩個 dataset 成功、第三個逾時的半包資料若封存 24 小時，
        // 所有重試都只會命中同一份半包。
        $calls = 0;
        $result = new FetchResult(FetchStatus::Partial, new PeriodFactSet([], 'us'));
        $source = new CachedFinancialStatementSource($this->inner($result, $calls));

        $source->fetch('NVDA', 12, 5);
        $source->fetch('NVDA', 12, 5);

        $this->assertSame(2, $calls);
    }

    public function test_failed_and_unsupported_are_never_cached(): void
    {
        foreach ([FetchStatus::Failed, FetchStatus::Unsupported] as $status) {
            Cache::flush();
            $calls = 0;
            $source = new CachedFinancialStatementSource(
                $this->inner(new FetchResult($status, new PeriodFactSet), $calls)
            );

            $source->fetch('NVDA', 12, 5);
            $source->fetch('NVDA', 12, 5);

            $this->assertSame(2, $calls, "{$status->value} 不得進快取");
        }
    }

    public function test_normalizer_version_is_part_of_the_key(): void
    {
        // 部署新解析規則後，舊的正規化結果不得被繼續使用。
        $calls = 0;
        $result = new FetchResult(FetchStatus::Complete, new PeriodFactSet([], 'us'));
        $source = new CachedFinancialStatementSource($this->inner($result, $calls));

        $source->fetch('NVDA', 12, 5);

        config(['financial_statements.normalizer_version' => 999]);
        $source->fetch('NVDA', 12, 5);

        $this->assertSame(2, $calls);
    }

    public function test_different_windows_do_not_share_a_key(): void
    {
        $calls = 0;
        $result = new FetchResult(FetchStatus::Complete, new PeriodFactSet([], 'us'));
        $source = new CachedFinancialStatementSource($this->inner($result, $calls));

        $source->fetch('NVDA', 12, 5);
        $source->fetch('NVDA', 20, 10);

        $this->assertSame(2, $calls);
    }

    public function test_container_resolves_the_cached_routing_source(): void
    {
        $this->assertInstanceOf(
            CachedFinancialStatementSource::class,
            app(FinancialStatementSource::class)
        );
    }

    /**
     * 釘住「TTL 讀 config('financial_statements.cache_hours')」而不是寫死 24。
     *
     * 把 cache_hours 設為 0：0 小時換算成 0 秒，Laravel Repository::put() 對
     * <=0 秒的 TTL 會直接 forget（見 vendor Illuminate\Cache\Repository::put）。
     * 若實作寫死 24 小時，這裡仍會快取住、第二次呼叫不會再進 inner，測試就會
     * 抓不到「讀錯設定鍵」這個錯誤。
     */
    public function test_ttl_is_read_from_config_not_hardcoded(): void
    {
        config(['financial_statements.cache_hours' => 0]);

        $calls = 0;
        $result = new FetchResult(FetchStatus::Complete, new PeriodFactSet([], 'us'));
        $source = new CachedFinancialStatementSource($this->inner($result, $calls));

        $source->fetch('NVDA', 12, 5);
        $source->fetch('NVDA', 12, 5);

        $this->assertSame(2, $calls, 'cache_hours=0 時不該有任何一次快取存活');
    }

    /** TTL 到期後應該重新打 inner，而不是永久快取。 */
    public function test_cache_expires_after_configured_ttl(): void
    {
        config(['financial_statements.cache_hours' => 1]);

        $calls = 0;
        $result = new FetchResult(FetchStatus::Complete, new PeriodFactSet([], 'us'));
        $source = new CachedFinancialStatementSource($this->inner($result, $calls));

        $source->fetch('NVDA', 12, 5);
        $this->travel(61)->minutes();
        $source->fetch('NVDA', 12, 5);

        $this->assertSame(2, $calls, '超過 1 小時 TTL 後應該重新抓取');
    }
}
