<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PageRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_dashboard_to_login(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_render_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('auth.user.id', $user->id)
                ->where('auth.user.profile.theme', 'warm')
                ->where('summary.riskLevel', 'watch'));
    }

    public function test_authenticated_user_can_render_shell_placeholder_pages(): void
    {
        $user = User::factory()->create();

        foreach (['/news', '/watchlists', '/stocks/search', '/analyses', '/settings'] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Placeholder')
                    ->has('title')
                    ->where('auth.user.id', $user->id));
        }
    }
}
