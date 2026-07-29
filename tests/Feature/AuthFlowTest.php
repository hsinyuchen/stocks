<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_render_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    /**
     * 註冊只是提出申請，不再直接進站——詳細的審核行為見 RegistrationApprovalTest。
     * 這裡保留的是「帳號與 profile 仍然會被建立」這件事。
     */
    public function test_user_can_register_and_is_sent_back_to_login_for_approval(): void
    {
        $this->post('/register', [
            'name' => 'Demo User',
            'email' => 'demo@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['email' => 'demo@example.test', 'approved_at' => null]);
        $this->assertDatabaseHas('user_profiles', ['theme' => 'warm']);
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'demo@example.test',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'email' => 'demo@example.test',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_invalid_login_returns_validation_error(): void
    {
        User::factory()->create([
            'email' => 'demo@example.test',
            'password' => 'password',
        ]);

        $this->from('/login')
            ->post('/login', [
                'email' => 'demo@example.test',
                'password' => 'wrong-password',
            ])
            ->assertRedirect('/login')
            ->assertInvalid(['email']);

        $this->assertGuest();
    }
}
