<?php

namespace Tests\Feature\Topics;

use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Data\TopicCandidate;
use App\Enums\TopicDirection;
use App\Enums\TopicTier;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Topics\TopicCandidateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 題材鏈路的接合：config 的傳導表 → instruments／fundamentals → TopicBoard。
 *
 * 各層自己的測試都用手寫的 DTO 或單層 fixture，所以**跨層的資料形狀與語意落差
 * 不會被任何一邊發現**：fundamentals 的 JSON 路徑寫錯、order_inventory 的
 * industry 快照換了鍵名，兩邊的測試都照樣全綠，只有真正跑過整條鏈的測試會紅。
 * 本測試不手寫任何 DTO，建真的 Instrument／fundamentals 列，一路走到 board。
 */
class TopicSeamTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // 序列的季末日寫死在 2026-06-30，而資料時效比的是 now()；不凍結的話
        // 這條測試會壞在日曆上而不是程式碼改動上（同 OrderInventorySeamTest）。
        $this->now = CarbonImmutable::parse('2026-08-24 09:00:00');
        $this->travelTo($this->now);
    }

    #[Test]
    public function a_topic_flows_from_config_and_cached_rows_to_a_two_tier_board(): void
    {
        // 核心：傳導表「航運」（positive）與「航空」（negative）各一檔。
        $shipping = Instrument::factory()->create(['symbol' => '2603.TW', 'name' => '長榮']);
        $airline = Instrument::factory()->create(['symbol' => '2610.TW', 'name' => '華航']);
        $this->cachedSeries($shipping, '航運業');
        $this->cachedSeries($airline, '航空業');

        // 營收驗證要有結論，產業就得落在本框架適用的桶裡：「航運」在
        // order_inventory.industry.not_applicable 名單上（無進銷存循環），
        // 拿它斷言 revenueVerified 只會永遠是 null。塑化是同一個題材的核心，
        // 且落在 adjust 桶。
        $plastics = Instrument::factory()->create(['symbol' => '1301.TW', 'name' => '台塑']);
        $this->cachedSeries($plastics, '塑膠工業');

        // 延伸：與核心同產業、但不在傳導表裡。
        $peer = Instrument::factory()->create(['symbol' => '5608.TW', 'name' => '四維航']);
        $this->cachedSeries($peer, '航運業');

        $board = app(TopicCandidateResolver::class)->resolve('hormuz_oil', $this->now);

        $this->assertNotNull($board);
        $this->assertNotEmpty($board->chain, '傳導鏈的敘述要一路帶到 board');

        $this->assertSame(TopicTier::Core, $this->find($board->candidates, '2603.TW')?->tier);
        $this->assertSame(TopicDirection::Benefits, $this->find($board->candidates, '2603.TW')?->direction);
        $this->assertSame(TopicDirection::Harmed, $this->find($board->candidates, '2610.TW')?->direction);

        $extended = $this->find($board->candidates, '5608.TW');
        $this->assertSame(TopicTier::Extended, $extended?->tier);
        $this->assertSame(TopicDirection::Benefits, $extended?->direction, '延伸沿用來源核心的方向');
        $this->assertNull($extended?->sectorName);

        // 序列真的被讀到了：全 null 的話上面兩層仍然成立，而「營收驗證」
        // 這條線其實從未接上。
        $this->assertTrue(
            $this->find($board->candidates, '1301.TW')?->revenueVerified,
            '已快取的序列必須一路走到營收驗證徽章',
        );
        $this->assertSame('塑膠工業', $this->find($board->candidates, '1301.TW')?->industry);
    }

    /** @param  list<TopicCandidate>  $candidates */
    private function find(array $candidates, string $symbol): ?TopicCandidate
    {
        foreach ($candidates as $candidate) {
            if ($candidate->symbol === $symbol) {
                return $candidate;
            }
        }

        return null;
    }

    /** 一列真的落在 fundamentals 表的序列，月營收連續成長讓 C1 成立。 */
    private function cachedSeries(Instrument $instrument, string $industry): void
    {
        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => '2026-06-30',
            'fetched_at' => $this->now->subDay(),
            'order_inventory' => (new OrderInventoryData(
                quarters: [
                    new QuarterlyFinancials(period: '2026Q1', endDate: '2026-03-31', revenue: 1000.0, costOfGoodsSold: 700.0, grossProfit: 300.0, inventories: 350.0),
                    new QuarterlyFinancials(period: '2026Q2', endDate: '2026-06-30', revenue: 1100.0, costOfGoodsSold: 760.0, grossProfit: 340.0, inventories: 360.0),
                ],
                monthlyRevenue: [
                    ['month' => '2026-03-01', 'revenue' => 900.0, 'yoy' => 0.05],
                    ['month' => '2026-04-01', 'revenue' => 950.0, 'yoy' => 0.06],
                    ['month' => '2026-05-01', 'revenue' => 980.0, 'yoy' => 0.07],
                    ['month' => '2026-06-01', 'revenue' => 1000.0, 'yoy' => 0.08],
                ],
                market: 'tw',
                industry: $industry,
                dataAsOf: '2026-06-30',
            ))->toArray(),
        ]);
    }
}
