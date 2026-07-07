<?php

namespace Tests\Feature;

use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * instruments:fix-index-metadata：修正歷史錯標的指數 instrument。
 * MarketResolver 修正前，^TWII 等指數被依 .TW 後綴規則錯標成
 * market=US/currency=USD/asset_type=stock；此命令依現行 resolver 重算。
 */
class FixIndexInstrumentMetadataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_mislabeled_index_rows_are_fixed(): void
    {
        Instrument::query()->create([
            'symbol' => '^TWII',
            'name' => '^TWII',
            'market' => 'US',
            'asset_type' => 'stock',
            'currency' => 'USD',
            'exchange' => null,
        ]);

        $this->artisan('instruments:fix-index-metadata')
            ->assertSuccessful();

        $this->assertDatabaseHas('instruments', [
            'symbol' => '^TWII',
            'market' => 'TW',
            'currency' => 'TWD',
            'asset_type' => 'index',
        ]);
    }

    public function test_us_index_rows_get_index_asset_type_only(): void
    {
        Instrument::query()->create([
            'symbol' => '^GSPC',
            'name' => '^GSPC',
            'market' => 'US',
            'asset_type' => 'stock',
            'currency' => 'USD',
            'exchange' => null,
        ]);

        $this->artisan('instruments:fix-index-metadata')->assertSuccessful();

        $this->assertDatabaseHas('instruments', [
            'symbol' => '^GSPC',
            'market' => 'US',
            'currency' => 'USD',
            'asset_type' => 'index',
        ]);
    }

    public function test_non_index_rows_are_untouched(): void
    {
        Instrument::query()->create([
            'symbol' => '2330.TW',
            'name' => '台積電',
            'market' => 'TW',
            'asset_type' => 'stock',
            'currency' => 'TWD',
            'exchange' => 'TWSE',
        ]);
        Instrument::query()->create([
            'symbol' => 'NVDA',
            'name' => 'NVIDIA',
            'market' => 'US',
            'asset_type' => 'stock',
            'currency' => 'USD',
            'exchange' => 'NASDAQ',
        ]);

        $this->artisan('instruments:fix-index-metadata')->assertSuccessful();

        $this->assertDatabaseHas('instruments', ['symbol' => '2330.TW', 'asset_type' => 'stock', 'market' => 'TW']);
        $this->assertDatabaseHas('instruments', ['symbol' => 'NVDA', 'asset_type' => 'stock', 'market' => 'US']);
    }

    public function test_already_correct_index_rows_are_not_rewritten(): void
    {
        $instrument = Instrument::query()->create([
            'symbol' => '^TWII',
            'name' => '加權指數',
            'market' => 'TW',
            'asset_type' => 'index',
            'currency' => 'TWD',
            'exchange' => null,
        ]);
        $originalUpdatedAt = $instrument->updated_at;

        $this->travel(1)->hours();

        $this->artisan('instruments:fix-index-metadata')->assertSuccessful();

        // 名稱等其他欄位不動，且未產生無意義的 updated_at 變動。
        $this->assertSame(
            $originalUpdatedAt->toDateTimeString(),
            $instrument->fresh()->updated_at->toDateTimeString(),
        );
        $this->assertSame('加權指數', $instrument->fresh()->name);
    }
}
