<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 使用者自行維護帳號資料。
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['approved_at' => now()], $overrides));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_page_exposes_account_and_preferences(): void
    {
        $user = $this->user(['name' => '研究員']);

        $props = $this->actingAs($user)->get('/profile')->assertOk()->viewData('page')['props'];

        $this->assertSame('研究員', $props['account']['name']);
        $this->assertSame($user->email, $props['account']['email']);
        $this->assertSame('warm', $props['preferences']['theme']);
    }

    public function test_user_can_update_name_and_email(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->patch('/profile', ['name' => '新名字', 'email' => 'new@example.com'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $user->refresh();

        $this->assertSame('新名字', $user->name);
        $this->assertSame('new@example.com', $user->email);
    }

    public function test_keeping_the_same_email_is_not_treated_as_a_duplicate(): void
    {
        $user = $this->user();

        // 只改姓名時 email 原封不動送出，唯一性檢查必須排除自己。
        $this->actingAs($user)
            ->patch('/profile', ['name' => '只改名字', 'email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertSame('只改名字', $user->fresh()->name);
    }

    public function test_email_taken_by_someone_else_is_rejected(): void
    {
        $user = $this->user();
        $other = $this->user();

        $this->actingAs($user)
            ->patch('/profile', ['name' => $user->name, 'email' => $other->email])
            ->assertSessionHasErrors('email');

        $this->assertNotSame($other->email, $user->fresh()->email);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = $this->user(['password' => 'old-password']);

        $this->actingAs($user)
            ->patch('/profile/password', [
                'current_password' => 'wrong-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertSessionHasErrors('current_password');

        // 沒有這道檢查，任何拿到未鎖螢幕的人都能把本人鎖在外面。
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_user_can_change_password_with_the_correct_current_one(): void
    {
        $user = $this->user(['password' => 'old-password']);

        $this->actingAs($user)
            ->patch('/profile/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        // 改完密碼不該把自己登出。
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_new_password_must_be_confirmed_and_different(): void
    {
        $user = $this->user(['password' => 'old-password']);

        $this->actingAs($user)
            ->patch('/profile/password', [
                'current_password' => 'old-password',
                'password' => 'old-password',
                'password_confirmation' => 'old-password',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->patch('/profile/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'mismatch-password',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_user_can_update_preferences(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->patch('/profile/preferences', [
                'theme' => 'dark',
                'locale' => 'en',
                'timezone' => 'America/New_York',
                'preferred_market' => 'US',
            ])
            ->assertSessionHas('success');

        $profile = $user->fresh()->profile;

        $this->assertSame('dark', $profile->theme);
        $this->assertSame('en', $profile->locale);
        $this->assertSame('America/New_York', $profile->timezone);
        $this->assertSame('US', $profile->preferred_market);
    }

    public function test_locale_can_be_updated_on_its_own(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->post('/profile/locale', ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('en', $user->fresh()->profile->locale);
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->post('/profile/locale', ['locale' => 'fr'])
            ->assertSessionHasErrors('locale');
    }

    public function test_invalid_preferences_are_rejected(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->patch('/profile/preferences', [
                'theme' => 'neon',
                'timezone' => 'Mars/Olympus',
                'preferred_market' => 'JP',
            ])
            ->assertSessionHasErrors(['theme', 'timezone', 'preferred_market']);
    }

    public function test_a_user_cannot_touch_another_users_profile(): void
    {
        $user = $this->user();
        $other = $this->user(['name' => '別人']);

        // 路由完全以登入者為準，沒有可指定他人的參數；這裡確認改動只落在自己身上。
        $this->actingAs($user)->patch('/profile', ['name' => '我改的', 'email' => $user->email]);

        $this->assertSame('別人', $other->fresh()->name);
    }
}
