<?php

namespace Tests\Feature\Admin;

use App\Models\Instrument;
use App\Models\LlmProviderSetting;
use App\Models\StockAnalysis;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDeleteUserTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        return $admin;
    }

    public function test_delete_removes_user_and_all_owned_data_but_keeps_shared_data(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create();
        $watchlist = $user->watchlists()->create(['name' => 'W']);
        $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);
        $user->stockAnalyses()->create([
            'instrument_id' => $instrument->id,
            'technical_snapshot_id' => null,
            'provider_type' => 'none',
            'model' => 'm',
            'prompt_version' => 'v1',
            'rule_signal' => ['stance' => 'watch'],
            'llm_output' => ['content' => 'x'],
            'data_as_of' => now(),
        ]);
        $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'L',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.2,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);

        $this->actingAs($admin)->delete("/admin/users/{$user->id}")->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, UserProfile::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, Watchlist::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, WatchlistItem::query()->count());
        $this->assertSame(0, StockAnalysis::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, LlmProviderSetting::query()->where('user_id', $user->id)->count());
        // 全站共用資料不受影響
        $this->assertDatabaseHas('instruments', ['id' => $instrument->id]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
