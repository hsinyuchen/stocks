<?php

namespace Tests\Feature\Screener;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenerWarmCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_warm_iterates_universe_and_reports(): void
    {
        config(['screener.universe' => [
            ['symbol' => 'AAA', 'name' => 'Alpha'],
            ['symbol' => 'BBB', 'name' => 'Beta'],
        ]]);

        $this->artisan('screener:warm')
            ->expectsOutputToContain('2 / 2')
            ->assertExitCode(0);
    }
}
