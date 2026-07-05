<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCreateUserTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        return $admin;
    }

    public function test_admin_creates_user_with_given_password(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'initial-password-1',
        ])->assertRedirect();

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('initial-password-1', $user->password));
        $this->assertFalse($user->is_admin);
        // booted() 既有流程：自動建立 profile
        $this->assertNotNull($user->profile);
    }

    public function test_admin_creates_user_without_password_gets_generated_one_back(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Gen User',
            'email' => 'gen@example.com',
            'password' => null,
        ]);

        $response->assertRedirect()->assertSessionHas('generated_password');

        $generated = session('generated_password');
        $user = User::query()->where('email', 'gen@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($generated, $user->password));
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        User::factory()->create(['email' => 'dup@example.com']);

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'whatever-123',
        ])->assertSessionHasErrors('email');
    }

    public function test_password_shorter_than_min_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Short',
            'email' => 'short@example.com',
            'password' => 'a234567',
        ])->assertSessionHasErrors('password');

        $this->assertNull(User::query()->where('email', 'short@example.com')->first());
    }
}
