<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_defaults_to_non_admin_and_enabled(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->is_admin);
        $this->assertNull($user->disabled_at);
    }

    public function test_is_admin_and_disabled_at_are_not_mass_assignable(): void
    {
        $user = User::query()->create([
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password-123',
            'is_admin' => true,
            'disabled_at' => now(),
        ]);

        $user->refresh();

        $this->assertFalse($user->is_admin);
        $this->assertNull($user->disabled_at);
    }
}
