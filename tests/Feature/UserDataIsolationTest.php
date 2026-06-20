<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\LlmProviderSetting;
use App\Models\DailyPrice;
use App\Models\User;
use App\Models\Watchlist;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_watchlists_belong_to_one_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Watchlist::factory()->for($alice)->create(['name' => 'Alice AI']);
        Watchlist::factory()->for($bob)->create(['name' => 'Bob ETFs']);

        $this->assertSame(['Alice AI'], Watchlist::query()->whereBelongsTo($alice)->pluck('name')->all());
        $this->assertSame(['Bob ETFs'], Watchlist::query()->whereBelongsTo($bob)->pluck('name')->all());
    }

    public function test_instrument_symbol_is_unique(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Instrument::factory()->create(['symbol' => '2330.TW']);
    }

    public function test_instrument_factory_can_create_ten_unique_default_symbols(): void
    {
        $instruments = Instrument::factory()->count(10)->create();

        $this->assertCount(10, $instruments);
        $this->assertCount(10, $instruments->pluck('symbol')->unique());
        $this->assertSame(10, Instrument::query()->count());
    }

    public function test_llm_provider_setting_hides_encrypted_api_key_in_serialization(): void
    {
        $setting = new LlmProviderSetting([
            'provider_type' => 'openai',
            'display_name' => 'Primary',
            'base_url' => 'https://api.example.test',
            'api_key_encrypted' => 'ciphertext',
            'model' => 'gpt-5',
            'timeout_seconds' => 60,
            'temperature' => '0.20',
            'max_tokens' => 1200,
            'is_default' => true,
        ]);

        $serialized = $setting->toArray();

        $this->assertArrayNotHasKey('api_key_encrypted', $serialized);
    }

    public function test_daily_price_casts_decimal_fields_and_date_with_normalized_scale(): void
    {
        $instrument = Instrument::factory()->create();

        $price = DailyPrice::query()->create([
            'instrument_id' => $instrument->id,
            'priced_at' => '2026-06-20',
            'open' => '123.4567',
            'high' => '125.0000',
            'low' => '120.1000',
            'close' => '124.9876',
            'volume' => '1000',
        ])->fresh();

        $this->assertInstanceOf(CarbonInterface::class, $price->priced_at);
        $this->assertSame('123.4567', $price->open);
        $this->assertSame('125.0000', $price->high);
        $this->assertSame('120.1000', $price->low);
        $this->assertSame('124.9876', $price->close);
        $this->assertSame(1000, $price->volume);
    }
}
