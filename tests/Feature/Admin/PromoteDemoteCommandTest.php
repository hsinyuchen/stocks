<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoteDemoteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_promote_makes_user_admin(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com']);

        $this->artisan('user:promote', ['email' => 'boss@example.com'])
            ->assertExitCode(0);

        $this->assertTrue($user->refresh()->is_admin);
    }

    public function test_promote_unknown_email_fails(): void
    {
        $this->artisan('user:promote', ['email' => 'nobody@example.com'])
            ->assertExitCode(1);
    }

    public function test_promote_trims_and_lowercases_email_argument(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com']);

        $this->artisan('user:promote', ['email' => '  Boss@Example.com  '])
            ->assertExitCode(0);

        $this->assertTrue($user->refresh()->is_admin);
    }

    public function test_demote_removes_admin(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $other = User::factory()->create();
        $other->is_admin = true;
        $other->save();

        $this->artisan('user:demote', ['email' => $admin->email])
            ->assertExitCode(0);

        $this->assertFalse($admin->refresh()->is_admin);
    }

    public function test_demote_last_admin_is_refused(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        $this->artisan('user:demote', ['email' => $admin->email])
            ->assertExitCode(1);

        $this->assertTrue($admin->refresh()->is_admin);
    }

    public function test_demote_disabled_admin_succeeds_even_when_one_active_admin_remains(): void
    {
        $disabledAdmin = User::factory()->create();
        $disabledAdmin->is_admin = true;
        $disabledAdmin->disabled_at = now();
        $disabledAdmin->save();

        $activeAdmin = User::factory()->create();
        $activeAdmin->is_admin = true;
        $activeAdmin->save();

        $this->artisan('user:demote', ['email' => $disabledAdmin->email])
            ->assertExitCode(0);

        $this->assertFalse($disabledAdmin->refresh()->is_admin);
    }
}
