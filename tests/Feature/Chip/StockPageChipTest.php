<?php

namespace Tests\Feature\Chip;

use App\Models\ChipFlow;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockPageChipTest extends TestCase
{
    use RefreshDatabase;

    /** 台股個股頁必須帶出籌碼序列（fake driver 產生確定性資料）。 */
    public function test_taiwan_stock_page_exposes_chip_flows(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/stocks/search?symbol=2330.TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->has('chipFlows')
                ->has('chipFlows.0', fn (Assert $row) => $row
                    ->has('date')
                    ->has('foreign_net')
                    ->has('trust_net')
                    ->has('dealer_net')
                    ->has('total_net')));
    }

    /** 美股沒有籌碼資料，須為空陣列而非 null，前端才不必額外判型。 */
    public function test_us_stock_page_exposes_empty_chip_flows(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/stocks/search?symbol=NVDA')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('chipFlows', []));
    }

    /** 頁面只輸出最近 20 個交易日，快取本身仍可保留更長歷史。 */
    public function test_chip_payload_is_capped_at_twenty_rows(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2317.TW']);

        for ($i = 0; $i < 40; $i++) {
            ChipFlow::query()->create([
                'instrument_id' => $instrument->id,
                'traded_at' => now()->subDays(40 - $i)->toDateString(),
                'foreign_net' => 1000 * $i,
                'trust_net' => 0,
                'dealer_net' => 0,
                'total_net' => 1000 * $i,
            ]);
        }

        $this->actingAs($user)
            ->get('/stocks/search?symbol=2317.TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('chipFlows', 20));
    }

    /** 分析寫入時，rule_signal 必須含籌碼區塊與 alignment。 */
    public function test_stored_analysis_rule_signal_includes_chip_block_for_taiwan_stock(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $this->actingAs($user)
            ->post("/stocks/{$instrument->id}/analyses", ['model' => 'reference-model'])
            ->assertRedirect();

        $analysis = $user->stockAnalyses()->firstOrFail();

        $this->assertArrayHasKey('chip', $analysis->rule_signal);
        $this->assertArrayHasKey('alignment', $analysis->rule_signal);
        $this->assertContains(
            $analysis->rule_signal['chip']['stance'],
            ['accumulating', 'distributing', 'neutral'],
        );

        // stance 語意不得被籌碼改動——alerts 與 dashboard 都依賴它。
        $this->assertContains(
            $analysis->rule_signal['stance'],
            ['bullish', 'bearish', 'neutral', 'watch', 'insufficient_data'],
        );
    }

    /** 美股分析不得出現籌碼欄位，維持與過去完全一致的形狀。 */
    public function test_stored_analysis_has_no_chip_block_for_us_stock(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);

        $this->actingAs($user)
            ->post("/stocks/{$instrument->id}/analyses", ['model' => 'reference-model'])
            ->assertRedirect();

        $analysis = $user->stockAnalyses()->firstOrFail();

        $this->assertArrayNotHasKey('chip', $analysis->rule_signal);
        $this->assertArrayNotHasKey('alignment', $analysis->rule_signal);
    }
}
