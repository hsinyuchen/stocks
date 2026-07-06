<?php

namespace Tests\Feature\Admin;

use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserListTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        return $admin;
    }

    public function test_list_shows_users_with_stats(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $instrument = Instrument::factory()->create();
        $watchlist = $user->watchlists()->create(['name' => 'W']);
        $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);
        $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Local',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.2,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users')
                ->has('users.data', 2)
                ->where('users.data.1.email', 'alice@example.com')
                ->where('users.data.1.watchlists_count', 1)
                ->where('users.data.1.has_llm', true)
                ->where('users.data.0.is_admin', true));
    }

    public function test_search_filters_by_email_or_name(): void
    {
        $admin = $this->makeAdmin();
        User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->actingAs($admin)
            ->get('/admin/users?q=alice')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Alice')
                ->where('filters.q', 'alice'));
    }
}
