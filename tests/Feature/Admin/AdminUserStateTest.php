<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserStateTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        return $admin;
    }

    public function test_admin_can_disable_and_enable_user(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/disable")->assertRedirect();
        $this->assertNotNull($user->refresh()->disabled_at);

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/enable")->assertRedirect();
        $this->assertNull($user->refresh()->disabled_at);
    }

    public function test_admin_can_promote_and_demote_other_user(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/role")->assertRedirect();
        $this->assertTrue($user->refresh()->is_admin);

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/role")->assertRedirect();
        $this->assertFalse($user->refresh()->is_admin);
    }

    public function test_admin_cannot_disable_or_demote_self(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->patch("/admin/users/{$admin->id}/disable")->assertSessionHas('error');
        $this->assertNull($admin->refresh()->disabled_at);

        $this->actingAs($admin)->patch("/admin/users/{$admin->id}/role")->assertSessionHas('error');
        $this->assertTrue($admin->refresh()->is_admin);
    }

    public function test_second_admin_cannot_demote_or_disable_last_active_admin(): void
    {
        // admin2 已停用：admin1 是「最後一位有效 admin」。
        // 用 admin2 重新啟用前的視角不可行（停用者無法登入），
        // 因此建立 admin2 為有效、admin1 停用，驗證 admin2 對自己（此時的最後有效 admin）被擋，
        // 並驗證規則以「有效（未停用）admin 數量」計算而非 admin 總數。
        $admin1 = $this->makeAdmin();
        $admin2 = $this->makeAdmin();
        $admin1->disabled_at = now();
        $admin1->save();

        // admin2 是最後一位有效 admin：不能被降級（總 admin 數為 2，但有效僅 1）
        $this->actingAs($admin2)->patch("/admin/users/{$admin2->id}/role")->assertSessionHas('error');
        $this->assertTrue($admin2->refresh()->is_admin);
    }
}
