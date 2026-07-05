<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        return $admin;
    }

    public function test_admin_sends_reset_link(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $user = User::factory()->create();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/reset-link")->assertRedirect();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_page_renders_and_user_can_reset_with_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->get("/reset-password/{$token}?email={$user->email}")->assertOk();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check('brand-new-password-1', $user->refresh()->password));
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $old = $user->password;

        $this->post('/reset-password', [
            'token' => 'bogus-token',
            'email' => $user->email,
            'password' => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertSessionHasErrors('email');

        $this->assertSame($old, $user->refresh()->password);
    }
}
