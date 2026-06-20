<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\User;
use App\Models\Watchlist;
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
}
