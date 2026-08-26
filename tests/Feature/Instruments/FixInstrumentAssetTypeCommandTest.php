<?php

namespace Tests\Feature\Instruments;

use App\Enums\AssetType;
use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixInstrumentAssetTypeCommandTest extends TestCase
{
    use RefreshDatabase;

    private function instrument(string $symbol, AssetType $stored): Instrument
    {
        $instrument = Instrument::factory()->create(['symbol' => $symbol]);
        // factory 走 resolver，所以先建再強制寫回錯的值，才能模擬修正前的資料。
        $instrument->forceFill(['asset_type' => $stored->value])->save();

        return $instrument->refresh();
    }

    #[Test]
    public function it_relabels_etfs_that_were_stored_as_stocks(): void
    {
        $etf = $this->instrument('QQQ', AssetType::Stock);
        $twEtf = $this->instrument('0050.TW', AssetType::Stock);
        $stock = $this->instrument('2330.TW', AssetType::Stock);
        $index = $this->instrument('^GSPC', AssetType::Index);

        $this->artisan('instruments:fix-asset-type')->assertSuccessful();

        $this->assertSame(AssetType::Etf, $etf->refresh()->asset_type);
        $this->assertSame(AssetType::Etf, $twEtf->refresh()->asset_type);
        $this->assertSame(AssetType::Stock, $stock->refresh()->asset_type, '個股不得被改動');
        $this->assertSame(AssetType::Index, $index->refresh()->asset_type, '指數不得被改動');
    }

    /**
     * 已正確的列不得被寫入。用 updated_at 驗——只重算不存檔的話，一支
     * 「全部重存一次」的命令在功能上看起來也是對的，但會把整張表的時戳洗掉。
     */
    #[Test]
    public function it_leaves_already_correct_rows_untouched(): void
    {
        $stock = $this->instrument('2330.TW', AssetType::Stock);
        $before = $stock->updated_at;

        $this->travel(1)->hour();
        $this->artisan('instruments:fix-asset-type')->assertSuccessful();

        $this->assertEquals($before, $stock->refresh()->updated_at);
    }
}
