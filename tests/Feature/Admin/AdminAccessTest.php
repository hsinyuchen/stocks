<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{string, string}>
     */
    private function adminEndpoints(int $targetId): array
    {
        return [
            ['get', '/admin/users'],
            ['post', '/admin/users'],
            ['patch', "/admin/users/{$targetId}/disable"],
            ['patch', "/admin/users/{$targetId}/enable"],
            ['patch', "/admin/users/{$targetId}/role"],
            ['post', "/admin/users/{$targetId}/reset-link"],
            ['delete', "/admin/users/{$targetId}"],
        ];
    }

    public function test_guest_is_redirected_to_login_for_all_admin_endpoints(): void
    {
        $target = User::factory()->create();

        foreach ($this->adminEndpoints($target->id) as [$method, $uri]) {
            $response = $this->{$method}($uri);

            $this->assertSame(
                302,
                $response->getStatusCode(),
                "guest {$method} {$uri} 應轉向登入頁",
            );
            $this->assertSame(
                route('login'),
                $response->headers->get('Location'),
                "guest {$method} {$uri} 應轉向登入頁",
            );
        }
    }

    public function test_non_admin_gets_403_for_all_admin_endpoints(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        foreach ($this->adminEndpoints($target->id) as [$method, $uri]) {
            $response = $this->actingAs($user)->{$method}($uri);

            $this->assertSame(
                403,
                $response->getStatusCode(),
                "non-admin {$method} {$uri} 應回 403",
            );
        }
    }

    public function test_admin_can_open_users_page(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }
}
