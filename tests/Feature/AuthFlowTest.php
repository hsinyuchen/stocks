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

    public function test_user_can_register_and_is_redirected_to_dashboard(): void
    {
        $this->post('/register', [
            'name' => 'Demo User',
            'email' => 'demo@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'demo@example.test']);
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
