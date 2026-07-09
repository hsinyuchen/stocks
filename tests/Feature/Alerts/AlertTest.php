<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use RefreshDatabase;

    private function makeAlert(User $user, Instrument $instrument): Alert
    {
        $alert = new Alert([
            'instrument_id' => $instrument->id,
            'type' => 'price_above',
            'threshold' => 100,
        ]);
        $user->alerts()->save($alert);

        return $alert;
    }

    public function test_privileged_fields_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $instrument = Instrument::factory()->create();

        $alert = new Alert([
            'instrument_id' => $instrument->id,
            'type' => 'price_above',
            'threshold' => 100,
            'user_id' => $other->id,
            'status' => 'triggered',
            'triggered_price' => 999,
        ]);
        $user->alerts()->save($alert);
        $alert->refresh();

        $this->assertSame($user->id, $alert->user_id);
        $this->assertSame('active', $alert->status);
        $this->assertNull($alert->triggered_price);
    }

    public function test_decimal_casts_return_strings(): void
    {
        $user = User::factory()->create();
        $alert = $this->makeAlert($user, Instrument::factory()->create());
        $alert->refresh();

        $this->assertIsString($alert->threshold);
        $this->assertSame('100.0000', $alert->threshold);
    }

    public function test_status_defaults_to_active(): void
    {
        $user = User::factory()->create();
        $alert = $this->makeAlert($user, Instrument::factory()->create());

        $this->assertSame('active', $alert->refresh()->status);
    }

    public function test_instrument_with_alerts_cannot_be_deleted(): void
    {
        $instrument = Instrument::factory()->create();
        $this->makeAlert(User::factory()->create(), $instrument);

        $this->expectException(QueryException::class);
        $instrument->delete();
    }

    public function test_deleting_user_cascades_alerts(): void
    {
        $user = User::factory()->create();
        $this->makeAlert($user, Instrument::factory()->create());

        $user->delete();

        $this->assertSame(0, Alert::query()->count());
    }
}
