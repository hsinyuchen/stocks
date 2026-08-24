<?php

namespace Tests\Feature\OrderInventory;

use App\Models\Instrument;
use App\Services\Fundamentals\OrderInventoryAssessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 階段 1（資料層）與階段 2（判定層）的接合。
 *
 * 兩個階段各自的測試都用手寫的 DTO，所以**跨階段的資料形狀與語意落差不會被任何
 * 一邊發現**：階段 1 的 provider 少填一個欄位、或 FundamentalsService 落地後
 * 還原不回來，兩邊的測試都照樣全綠，只有真正跑過整條鏈的測試會紅。本測試走完
 * provider → FundamentalsService（落地 + 還原）→ Assessor → 評級，不手寫任何 DTO，
 * 並針對「階段 2 實際會消費、階段 1 卻可能悄悄不填」的欄位下斷言：季末日期
 * （時效判定的唯一輸入）與產業（產業桶與同業取樣的唯一輸入）。
 */
class OrderInventorySeamTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 時間凍結：FakeCompanyFinancialsProvider 的季末日寫死在 2026-06-30，而時效
     * 判定（max_quarter_age_days = 228 天）比的是 now()。不凍結的話這條測試會在
     * 2027-02-13 之後一律評成 insufficient——測試不會壞在程式碼改動上，而是壞在
     * 日曆上。凍結點取季末後 55 天（尚未超過 lagging 的 137 天）。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-24 09:00:00'));
    }

    #[Test]
    public function a_taiwan_symbol_flows_from_the_fake_provider_to_a_rating(): void
    {
        // phpunit.xml 鎖定 MARKET_DATA_DRIVER=fake，容器已綁 FakeCompanyFinancialsProvider。
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);

        $this->assertNotNull($result, 'fake provider 的序列必須能一路走到評級');
        // fake 的預設情境（營收與存貨同步緩步成長、毛利率持平、應付與合約負債同步
        // 增加）落在規則 4 的 B+：必要條件成立且至少一項備料支撐。列舉 enum 全部
        // 五個 case 的斷言結構上不可能失敗，等於沒有斷言。
        $this->assertSame('B+', $result['assessment']->rating->value);
        $this->assertNotEmpty($result['assessment']->fixedCaveats, '任何路徑都要有固定提示');

        // 季末日期：階段 1 每季都要填 endDate，階段 2 拿它算資料時效。漏填時
        // freshness 會靜默降級成 as_of=null／lagging=false／too_old=false——評級
        // 照樣算得出來，時效判定卻整個失效。
        $this->assertSame(
            '2026-06-30',
            $result['assessment']->freshness['as_of'],
            'as_of 必須是 fake 序列最新一季的季末日，不能是 null',
        );

        // 產業：階段 1 填在序列 DTO 裡，階段 2 用它決定產業桶（本框架適不適用）
        // 與同業取樣的分組。漏填時整檔會塌成 unknown 桶、同業取樣直接短路。
        $this->assertSame(
            'suited',
            $result['assessment']->industryBucket,
            'fake 的產業是「光電業」，必須落進 suited 桶',
        );
    }

    #[Test]
    public function the_taiwan_series_reaches_the_proxy_matrix_with_its_uncertainty_prefix(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $result = app(OrderInventoryAssessor::class)->forInstrument($instrument);
        $assessment = $result['assessment'];

        // 空陣列會讓下面的迴圈一條斷言都不跑，而測試依然 PASS
        // （phpunit.xml 沒有 beStrictAboutTestsThatDoNotTestAnything）。
        $this->assertNotEmpty($assessment->proxySignals, '台股序列必須走到代理矩陣並輸出訊號');

        // 台股的 provider 從不填存貨組成欄位，實測路徑不可達，只能是代理推論，
        // 因此每一條都必須帶不確定性前綴。
        foreach ($assessment->proxySignals as $line) {
            $this->assertStringContainsString(
                (string) config('order_inventory.narrative.proxy_prefix'),
                $line,
                '台股走到實測路徑代表旗標或 provider 有變，兩市場的確定性層級會被混淆',
            );
        }
    }
}
