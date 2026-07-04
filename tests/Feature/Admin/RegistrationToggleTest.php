<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_route_returns_403_when_registration_disabled(): void
    {
        config(['platform.registration_enabled' => false]);

        $this->post('/register', [
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertForbidden();
    }

    public function test_register_route_works_when_registration_enabled(): void
    {
        config(['platform.registration_enabled' => true]);

        $this->post('/register', [
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect('/dashboard');
    }

    public function test_login_page_exposes_registration_flag(): void
    {
        config(['platform.registration_enabled' => false]);

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('registrationEnabled', false));
    }
}
