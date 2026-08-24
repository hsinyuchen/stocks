<?php

namespace Tests\Feature\OrderInventory;

use App\Models\Instrument;
use App\Services\Fundamentals\OrderInventoryAssessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 階段 1（資料層）與階段 2（判定層）的接合。
 *
 * 兩個階段各自的測試都用手寫的 DTO，所以跨階段的語意落差不會被任何一邊發現——
 * 階段 2 的最終審查就是這樣漏掉 inventoryCompositionAvailable 的語意不一致。
 * 本測試走完 provider → FundamentalsService → Assessor，不手寫任何 DTO。
 */
class OrderInventorySeamTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_taiwan_symbol_flows_from_the_fake_provider_to_a_rating(): void
    {
        // phpunit.xml 鎖定 MARKET_DATA_DRIVER=fake，容器已綁 FakeCompanyFinancialsProvider。
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertNotNull($result, 'fake provider 的序列必須能一路走到評級');
        $this->assertContains(
            $result['assessment']->rating->value,
            ['B+', 'B', 'C', 'insufficient', 'not_applicable'],
        );
        $this->assertNotEmpty($result['assessment']->fixedCaveats, '任何路徑都要有固定提示');
    }

    #[Test]
    public function the_composition_flag_from_the_provider_does_not_swallow_the_proxy_matrix(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);
        $assessment = $result['assessment'];

        // 台股的 provider 寫死 inventoryCompositionAvailable: false 且從不填組成欄位，
        // 所以實測路徑不可達；若 proxySignals 有內容，它必須帶台股的不確定性前綴。
        foreach ($assessment->proxySignals as $line) {
            $this->assertStringContainsString(
                (string) config('order_inventory.narrative.proxy_prefix'),
                $line,
                '台股走到實測路徑代表旗標或 provider 有變，兩市場的確定性層級會被混淆',
            );
        }
    }
}
