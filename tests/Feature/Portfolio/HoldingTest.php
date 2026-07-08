<?php

namespace Tests\Feature\Portfolio;

use App\Models\Holding;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldingTest extends TestCase
{
    use RefreshDatabase;

    private function makeHolding(User $user, Instrument $instrument, string $currency = 'TWD'): Holding
    {
        $holding = new Holding([
            'instrument_id' => $instrument->id,
            'shares' => 10,
            'avg_cost' => 100,
        ]);
        $holding->currency = $currency;
        $user->holdings()->save($holding);

        return $holding;
    }

    public function test_user_id_and_currency_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $instrument = Instrument::factory()->create();

        // 惡意 payload 想塞 user_id / currency
        $holding = new Holding([
            'instrument_id' => $instrument->id,
            'shares' => 10,
            'avg_cost' => 100,
            'user_id' => $other->id,
            'currency' => 'JPY',
        ]);
        $holding->currency = 'TWD';
        $user->holdings()->save($holding);

        $holding->refresh();
        $this->assertSame($user->id, $holding->user_id);
        $this->assertSame('TWD', $holding->currency);
    }

    public function test_one_holding_per_user_and_instrument(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create();
        $this->makeHolding($user, $instrument);

        $this->expectException(QueryException::class);
        $this->makeHolding($user, $instrument);
    }

    public function test_different_users_may_hold_the_same_instrument(): void
    {
        $instrument = Instrument::factory()->create();
        $this->makeHolding(User::factory()->create(), $instrument);
        $this->makeHolding(User::factory()->create(), $instrument);

        $this->assertSame(2, Holding::query()->count());
    }

    public function test_decimal_casts_return_strings(): void
    {
        // PortfolioService 依賴此行為做 (float) 顯式轉型——釘住它。
        $user = User::factory()->create();
        $holding = $this->makeHolding($user, Instrument::factory()->create());

        $holding->refresh();
        $this->assertIsString($holding->shares);
        $this->assertSame('10.0000', $holding->shares);
        $this->assertSame('100.0000', $holding->avg_cost);
    }

    public function test_instrument_with_holdings_cannot_be_deleted(): void
    {
        // holdings 是使用者財務資料，不得因刪 shared instrument 而靜默消失。
        $instrument = Instrument::factory()->create();
        $this->makeHolding(User::factory()->create(), $instrument);

        $this->expectException(QueryException::class);
        $instrument->delete();
    }

    public function test_deleting_user_cascades_holdings(): void
    {
        $user = User::factory()->create();
        $this->makeHolding($user, Instrument::factory()->create());

        $user->delete();

        $this->assertSame(0, Holding::query()->count());
    }
}
