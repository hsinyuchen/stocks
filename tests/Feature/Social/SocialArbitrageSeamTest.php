<?php

namespace Tests\Feature\Social;

use App\Enums\SocialArbitrageStage;
use App\Models\ChipFlow;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Models\NewsItem;
use App\Services\Social\SocialArbitrageAssessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 熱度（Task 1）、分類（Task 2）與 IO 邊界（Task 3）的接合。
 *
 * 前兩者的測試都用手寫的輸入，所以**跨層的資料形狀與語意落差不會被任何一邊發現**：
 * IO 層少填一條腿、或把 `null` 寫成 `0`、或視窗算在別的日子上，三層的測試都照樣
 * 全綠，只有真正跑過整條鏈的測試會紅。本測試從真實的 `news_items`／`daily_prices`／
 * `chip_flows` 列出發，不手寫任何 DTO，一路走到最終分類，並針對「分類層實際會消費、
 * IO 層卻可能悄悄不填」的東西下斷言：四條腿各自的可評估性與最終階段。
 */
class SocialArbitrageSeamTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-24 09:00:00');
        $this->travelTo($this->now);
    }

    #[Test]
    public function a_taiwan_symbol_flows_from_raw_rows_to_a_stage(): void
    {
        $window = (int) config('order_inventory.social.heat_window_days');
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        // 熱度：新期 4 則、前期 0 則（roseFromZero）→ 升溫且樣本達 min_recent_mentions。
        // relevant 與 related_symbols 都要照實填：NewsHeatCalculator 有 ->relevant()
        // 述詞，漏填會讓熱度恆為 0，整條煙霧測試退化成「測 Insufficient 分支」。
        foreach ([0, 2, 4, 6] as $daysAgo) {
            NewsItem::query()->create([
                'title' => "台積電 CoWoS 擴產 {$daysAgo}",
                'url' => 'https://example.com/news/'.$daysAgo,
                'source' => 'test',
                'published_at' => $this->now->subDays($daysAgo),
                'related_symbols' => ['2330.TW'],
                'relevant' => true,
            ]);
        }

        // 股價：視窗內 100 → 110（+10%），達 price_risen（0.08）但未達 price_surged（0.20）。
        $this->price($instrument, $window - 4, 100.0, 5_000_000);
        $this->price($instrument, 0, 110.0, 5_000_000);

        // 籌碼：淨買超 1,500,000 股 ÷ 同期成交量 10,000,000 股 = 0.15，
        // 達 foreign_net_buy_volume_share（0.10）但未達 heavy（0.20）。
        ChipFlow::query()->create([
            'instrument_id' => $instrument->id,
            'traded_at' => $this->now->subDays(1)->startOfDay(),
            'foreign_net' => 1_500_000,
            'trust_net' => 0,
            'dealer_net' => 0,
            'total_net' => 1_500_000,
        ]);

        $result = app(SocialArbitrageAssessor::class)->forInstrument($instrument, $this->now);

        $this->assertSame(4, $result->heat->recentCount, '新聞必須經 related_symbols 對上這一檔');
        $this->assertTrue($result->heatUp, '前期 0 則、新期 4 則是最強的升溫訊號');
        $this->assertTrue($result->priceLegEvaluable);
        $this->assertTrue($result->priceRisen, '+10% 達 price_risen');
        $this->assertFalse($result->priceSurged, '+10% 未達 price_surged');
        $this->assertTrue($result->foreignLegEvaluable, '台股有籌碼列，法人腿必須可評估');
        $this->assertTrue($result->foreignBuying, '0.15 達 foreign_net_buy_volume_share');
        $this->assertFalse($result->foreignBuyingHeavy, '0.15 未達 heavy 門檻');

        // 營收與毛利兩條腿沒有快取序列可讀（fundamentals 一列都沒有），
        // 必須是「算不出來」而不是「不成立」。
        $this->assertFalse($result->revenueLegEvaluable);
        $this->assertNull($result->revenueUnverified);
        $this->assertFalse($result->marginLegEvaluable);
        $this->assertNull($result->marginDeclining);

        $this->assertSame(SocialArbitrageStage::PartlyPriced, $result->stage);
        $this->assertNull($result->insufficientReason, '分得出階段時不得帶資料不足的原因');
    }

    private function price(Instrument $instrument, int $daysAgo, float $close, int $volume): void
    {
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id,
            'priced_at' => $this->now->subDays($daysAgo)->startOfDay(),
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => $volume,
        ]);
    }
}
