<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_default_profile(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->profile);
        $this->assertSame('warm', $user->profile->theme);
        $this->assertSame('Asia/Taipei', $user->profile->timezone);
        $this->assertSame('TW_US', $user->profile->preferred_market);
    }
}
