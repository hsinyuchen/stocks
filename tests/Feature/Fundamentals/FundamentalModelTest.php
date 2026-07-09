<?php

namespace Tests\Feature\Fundamentals;

use App\Models\Fundamental;
use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_row_per_instrument_and_decimal_casts_are_strings(): void
    {
        $instrument = Instrument::factory()->create();

        $row = Fundamental::query()->updateOrCreate(
            ['instrument_id' => $instrument->id],
            ['per' => 33.14, 'eps' => 22.08, 'fetched_at' => now()],
        );
        Fundamental::query()->updateOrCreate(
            ['instrument_id' => $instrument->id],
            ['per' => 30.0, 'fetched_at' => now()],
        );

        $this->assertSame(1, Fundamental::query()->count());
        $row->refresh();
        $this->assertIsString($row->per);
        $this->assertSame('30.0000', $row->per);
    }

    public function test_deleting_instrument_cascades_fundamentals(): void
    {
        $instrument = Instrument::factory()->create();
        Fundamental::query()->create(['instrument_id' => $instrument->id, 'fetched_at' => now()]);

        $instrument->delete();

        $this->assertSame(0, Fundamental::query()->count());
    }
}
