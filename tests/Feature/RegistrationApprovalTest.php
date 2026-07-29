<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 自助註冊改為需要管理員核准。
 *
 * 這道關卡的價值全在「未核准的帳號拿不到任何東西」——註冊當下拿不到 session，
 * 之後也登不進來，核准被撤銷時已登入的 session 立刻失效。
 */
class RegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'approved_at' => now()]);
    }

    /**
     * @return array<string, string>
     */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => '申請者',
            'email' => 'applicant@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ], $overrides);
    }

    public function test_registration_creates_an_unapproved_account_without_logging_in(): void
    {
        $this->post('/register', $this->registration())
            ->assertRedirect('/login')
            ->assertSessionHas('success');

        $user = User::query()->where('email', 'applicant@example.com')->sole();

        $this->assertNull($user->approved_at);
        $this->assertTrue($user->isPendingApproval());
        // 未核准的帳號連一次 session 都不該拿到。
        $this->assertGuest();
    }

    public function test_pending_account_cannot_log_in(): void
    {
        $this->post('/register', $this->registration());

        $this->post('/login', ['email' => 'applicant@example.com', 'password' => 'secret-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_approved_account_can_log_in(): void
    {
        $this->post('/register', $this->registration());
        $user = User::query()->where('email', 'applicant@example.com')->sole();

        $this->actingAs($this->admin())
            ->patch("/admin/users/{$user->id}/approve")
            ->assertRedirect();

        $this->post('/logout');

        $this->post('/login', ['email' => 'applicant@example.com', 'password' => 'secret-password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_approval_records_who_granted_it(): void
    {
        $admin = $this->admin();
        $this->post('/register', $this->registration());
        $user = User::query()->where('email', 'applicant@example.com')->sole();

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/approve");

        $user->refresh();

        $this->assertNotNull($user->approved_at);
        // 出事時要能追出是誰放行的。
        $this->assertSame($admin->id, $user->approved_by);
        $this->assertSame($admin->id, $user->approver->id);
    }

    /**
     * 待審核與已停用的訊息必須不同：一個該等，一個該去找管理員。兩者共用同一句
     * 話的話，被停用的人會一直等一個永遠不會來的核准。
     *
     * 分成兩個測試而不是一個：session invalidate 之後再讀同一個 session 的
     * errors 會拿到重置後的狀態，測不準。
     */
    public function test_pending_account_is_told_it_awaits_approval(): void
    {
        $pending = User::factory()->create(['approved_at' => null, 'password' => 'secret-password']);

        $this->post('/login', ['email' => $pending->email, 'password' => 'secret-password'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('審核', session('errors')->getBag('default')->first('email'));
    }

    public function test_disabled_account_is_told_it_was_disabled(): void
    {
        $disabled = User::factory()->create([
            'approved_at' => now(),
            'disabled_at' => now(),
            'password' => 'secret-password',
        ]);

        $this->post('/login', ['email' => $disabled->email, 'password' => 'secret-password'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('停用', session('errors')->getBag('default')->first('email'));
    }

    public function test_revoking_approval_logs_the_user_out_on_the_next_request(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill(['approved_at' => null])->save();

        // 已發出的 session 不能繼續有效，否則撤銷核准形同虛設。
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_created_accounts_are_approved_immediately(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/users', [
            'name' => '同事',
            'email' => 'colleague@example.com',
            'password' => 'secret-password',
        ])->assertRedirect();

        $created = User::query()->where('email', 'colleague@example.com')->sole();

        $this->assertNotNull($created->approved_at);
        $this->assertSame($admin->id, $created->approved_by);
    }

    public function test_rejecting_deletes_the_application_so_the_email_can_be_reused(): void
    {
        $this->post('/register', $this->registration());
        $user = User::query()->where('email', 'applicant@example.com')->sole();

        $this->actingAs($this->admin())
            ->delete("/admin/users/{$user->id}/reject")
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'applicant@example.com']);

        // 留著「已駁回」狀態會讓 email 永久佔住 unique 索引，本人再也無法重新申請。
        $this->post('/register', $this->registration())->assertSessionHasNoErrors();
    }

    public function test_approved_account_cannot_be_rejected(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($this->admin())
            ->delete("/admin/users/{$user->id}/reject")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_non_admin_cannot_approve(): void
    {
        $pending = User::factory()->create(['approved_at' => null]);
        $regular = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($regular)
            ->patch("/admin/users/{$pending->id}/approve")
            ->assertForbidden();

        $this->assertNull($pending->fresh()->approved_at);
    }

    public function test_pending_users_are_listed_first_with_a_count(): void
    {
        $admin = $this->admin();
        User::factory()->create(['approved_at' => now()]);
        $pending = User::factory()->create(['approved_at' => null]);

        $props = $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(1, $props['pendingCount']);
        // 待辦排在第三頁等於沒做。
        $this->assertSame($pending->id, $props['users']['data'][0]['id']);
    }
}
