<?php

namespace Tests\Feature\Screener;

use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenerWarmCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_warm_iterates_the_instrument_list_and_reports(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha']);
        Instrument::factory()->create(['symbol' => 'BBB', 'name' => 'Beta']);

        $this->artisan('screener:warm')
            ->expectsOutputToContain('2 / 2')
            ->assertExitCode(0);
    }

    /** 預載與掃描必須用同一份清單，否則會回到「預載了 A、掃描的是 B」。 */
    public function test_warm_skips_indices_like_the_scanner_does(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'asset_type' => 'stock']);
        Instrument::factory()->create(['symbol' => '^TWII', 'asset_type' => 'index']);

        $this->artisan('screener:warm')
            ->expectsOutputToContain('1 / 1')
            ->assertExitCode(0);
    }

    public function test_warm_explains_what_to_do_when_the_list_is_empty(): void
    {
        $this->artisan('screener:warm')
            ->expectsOutputToContain('instruments:seed-universe')
            ->assertExitCode(0);
    }
}
