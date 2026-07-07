<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * by-symbol chart endpoint（stocks.chart.symbol）：比較模式取用。
 * 比較 symbol 可能無現存 instrument（含指數如 ^TWII），故以 symbol 查詢，
 * 端點內部 firstOrCreate instrument 後與主端點共用回傳格式。
 */
class StockChartBySymbolEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected(): void
    {
        $this->get('/stocks/chart?symbol=NVDA')->assertRedirect('/login');
    }

    public function test_valid_symbol_returns_chart_payload(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/stocks/chart?symbol=nvda&tf=daily')
            ->assertOk()
            ->json();

        // symbol 正規化為大寫；與主端點格式一致。
        $this->assertSame('NVDA', $response['symbol']);
        $this->assertSame('daily', $response['timeframe']);
        $this->assertNotEmpty($response['candles']);
        $this->assertArrayHasKey('indicators', $response);

        $candle = $response['candles'][0];
        foreach (['time', 'open', 'high', 'low', 'close', 'volume'] as $key) {
            $this->assertArrayHasKey($key, $candle);
        }

        // 查過即建立 instrument，供後續分析/自選複用。
        $this->assertDatabaseHas('instruments', ['symbol' => 'NVDA']);
    }

    public function test_index_symbol_is_accepted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/stocks/chart?symbol=%5ETWII')
            ->assertOk()
            ->json();

        // regex 放寬允許前導 ^（指數），symbol 原樣保留。
        $this->assertSame('^TWII', $response['symbol']);
    }

    public function test_taiwan_index_instrument_gets_taiwan_metadata(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/stocks/chart?symbol=%5ETWII')->assertOk();

        // 回歸：^TWII 曾被依 .TW 後綴規則錯標成 market=US/currency=USD/stock。
        $this->assertDatabaseHas('instruments', [
            'symbol' => '^TWII',
            'market' => 'TW',
            'currency' => 'TWD',
            'asset_type' => 'index',
        ]);
    }

    public function test_malformed_symbol_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/stocks/chart?symbol=bad%20symbol%21')
            ->assertStatus(422);
    }
}
