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

        foreach (['/news', '/analyses'] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Placeholder')
                    ->has('title')
                    ->where('auth.user.id', $user->id));
        }
    }

    public function test_authenticated_user_can_render_settings_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->has('settings', 0)
                ->where('auth.user.id', $user->id));
    }

    public function test_authenticated_user_can_render_watchlists_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/watchlists')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Watchlists/Index')
                ->has('watchlists', 0)
                ->where('auth.user.id', $user->id));
    }

    public function test_authenticated_user_can_render_stock_search_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/stocks/search')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->where('symbol', null)
                ->where('auth.user.id', $user->id));
    }
}
