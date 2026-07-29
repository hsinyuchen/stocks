<?php

namespace Tests\Feature\Screener;

use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * config/screener.universe → 標的清單的一次性搬遷。
 *
 * 掃描範圍已改成標的清單，config 只剩初始種子的角色。沒有這個指令的話，切換
 * 當下只存在於 config 的股票會直接從選股器消失。
 */
class SeedInstrumentUniverseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_missing_instruments(): void
    {
        config(['screener.universe' => [
            ['symbol' => '2330.TW', 'name' => '台積電'],
            ['symbol' => 'AAPL', 'name' => 'Apple'],
        ]]);

        $this->artisan('instruments:seed-universe')->assertExitCode(0);

        $this->assertDatabaseHas('instruments', ['symbol' => '2330.TW', 'name' => '台積電']);
        $this->assertDatabaseHas('instruments', ['symbol' => 'AAPL', 'name' => 'Apple']);
        // 市場與幣別由 symbol 推導，與匯入流程一致。
        $this->assertSame('TW', Instrument::query()->where('symbol', '2330.TW')->value('market')->value);
        $this->assertSame('USD', Instrument::query()->where('symbol', 'AAPL')->value('currency'));
    }

    public function test_it_is_idempotent_and_keeps_admin_edits(): void
    {
        config(['screener.universe' => [['symbol' => '2330.TW', 'name' => '台積電']]]);

        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '管理員改過的名稱']);

        $this->artisan('instruments:seed-universe')->assertExitCode(0);

        // 已存在就跳過：重跑不得覆蓋管理員後來改的名稱。
        $this->assertSame(1, Instrument::query()->where('symbol', '2330.TW')->count());
        $this->assertSame('管理員改過的名稱', Instrument::query()->where('symbol', '2330.TW')->value('name'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        config(['screener.universe' => [['symbol' => '2330.TW', 'name' => '台積電']]]);

        $this->artisan('instruments:seed-universe', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('instruments', ['symbol' => '2330.TW']);
    }
}
