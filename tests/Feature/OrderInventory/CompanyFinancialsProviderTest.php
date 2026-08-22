<?php

namespace Tests\Feature\OrderInventory;

use App\Contracts\CompanyFinancialsProvider;
use App\Data\QuarterlyFinancials;
use App\Services\Fake\FakeCompanyFinancialsProvider;
use Tests\TestCase;

class CompanyFinancialsProviderTest extends TestCase
{
    public function test_fake_returns_a_deterministic_ascending_series(): void
    {
        $data = (new FakeCompanyFinancialsProvider)->financials('2330.TW', 30);

        $this->assertTrue($data->hasAny());
        $periods = array_map(static fn (QuarterlyFinancials $q): string => $q->period, $data->quarters);
        $sorted = $periods;
        sort($sorted);
        $this->assertSame($sorted, $periods, '季度序列必須舊→新');
        $this->assertGreaterThanOrEqual(6, count($data->quarters));
    }

    public function test_fake_defaults_to_no_inventory_composition(): void
    {
        // 與台股一致：預設沒有存貨組成，需要該情境的測試自行注入。
        $data = (new FakeCompanyFinancialsProvider)->financials('2330.TW', 30);

        $this->assertFalse($data->inventoryCompositionAvailable);
        $this->assertNull($data->latestQuarter()->inventoryFinishedGoods);
    }

    public function test_with_quarters_injects_an_arbitrary_series(): void
    {
        $provider = (new FakeCompanyFinancialsProvider)->withQuarters([
            new QuarterlyFinancials(period: '2026Q1', revenue: 100.0, inventories: 50.0),
            new QuarterlyFinancials(period: '2026Q2', revenue: 200.0, inventories: 90.0),
        ]);

        $data = $provider->financials('2330.TW', 30);

        $this->assertCount(2, $data->quarters);
        $this->assertSame(90.0, $data->latestQuarter()->inventories);
    }

    public function test_with_empty_reports_unavailable(): void
    {
        $data = (new FakeCompanyFinancialsProvider)->withEmpty()->financials('2330.TW', 30);

        $this->assertFalse($data->hasAny());
        $this->assertNull($data->latestQuarter());
    }

    public function test_container_resolves_fake_in_test_environment(): void
    {
        // phpunit.xml 鎖 MARKET_DATA_DRIVER=fake，測試不得打真實網路。
        $this->assertInstanceOf(
            FakeCompanyFinancialsProvider::class,
            app(CompanyFinancialsProvider::class),
        );
    }
}
