<?php

namespace Tests\Feature;

use App\Models\LlmProviderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LlmSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_settings_to_login(): void
    {
        $this->get('/settings')
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_render_settings_page_with_only_own_settings_and_without_api_key_props(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownSetting = $this->createSetting($user, [
            'display_name' => 'OpenAI Primary',
            'api_key_encrypted' => 'own-secret',
            'is_default' => true,
        ]);

        $this->createSetting($otherUser, [
            'display_name' => 'Other Secret',
            'api_key_encrypted' => 'other-secret',
        ]);

        $this->actingAs($user)
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->has('settings', 1)
                ->where('settings.0.id', $ownSetting->id)
                ->where('settings.0.display_name', 'OpenAI Primary')
                ->where('settings.0.has_api_key', true)
                ->missing('settings.0.api_key')
                ->missing('settings.0.api_key_encrypted')
                ->missing('settings.1'));
    }

    public function test_user_can_create_setting_with_encrypted_key_and_mark_default(): void
    {
        $user = User::factory()->create();
        $existingDefault = $this->createSetting($user, ['display_name' => 'Old Default', 'is_default' => true]);

        $this->actingAs($user)
            ->post('/settings', $this->validPayload([
                'provider_type' => 'openrouter',
                'display_name' => 'OpenRouter',
                'base_url' => 'https://openrouter.ai/api/v1',
                'api_key' => 'sk-test-secret',
                'model' => 'openai/gpt-5',
                'is_default' => true,
            ]))
            ->assertRedirect('/settings')
            ->assertValid();

        $setting = $user->llmProviderSettings()->where('display_name', 'OpenRouter')->firstOrFail();
        $storedValue = DB::table('llm_provider_settings')->where('id', $setting->id)->value('api_key_encrypted');

        $this->assertNotSame('sk-test-secret', $storedValue);
        $this->assertSame('sk-test-secret', $setting->fresh()->api_key_encrypted);
        $this->assertTrue($setting->fresh()->is_default);
        $this->assertFalse($existingDefault->fresh()->is_default);
    }

    public function test_user_can_create_llamacpp_setting_with_spec_provider_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings', $this->validPayload([
                'provider_type' => 'llamacpp',
                'display_name' => 'Remote llama.cpp',
                'base_url' => 'http://192.168.1.20:8080/v1',
                'api_key' => '',
                'model' => 'local-model',
            ]))
            ->assertRedirect('/settings')
            ->assertValid();

        $this->assertDatabaseHas('llm_provider_settings', [
            'user_id' => $user->id,
            'provider_type' => 'llamacpp',
            'display_name' => 'Remote llama.cpp',
        ]);
    }

    public function test_update_without_api_key_preserves_existing_key_and_update_with_api_key_changes_it(): void
    {
        $user = User::factory()->create();
        $setting = $this->createSetting($user, [
            'api_key_encrypted' => 'original-key',
            'model' => 'gpt-4.1',
        ]);
        $originalStoredValue = DB::table('llm_provider_settings')->where('id', $setting->id)->value('api_key_encrypted');

        $this->actingAs($user)
            ->patch("/settings/{$setting->id}", $this->validPayload([
                'display_name' => 'Renamed',
                'api_key' => '',
                'model' => 'gpt-5',
            ]))
            ->assertRedirect('/settings')
            ->assertValid();

        $afterBlankUpdate = DB::table('llm_provider_settings')->where('id', $setting->id)->value('api_key_encrypted');
        $this->assertSame($originalStoredValue, $afterBlankUpdate);
        $this->assertSame('original-key', $setting->fresh()->api_key_encrypted);
        $this->assertSame('gpt-5', $setting->fresh()->model);

        $this->actingAs($user)
            ->patch("/settings/{$setting->id}", $this->validPayload([
                'api_key' => 'replacement-key',
            ]))
            ->assertRedirect('/settings')
            ->assertValid();

        $afterReplacement = DB::table('llm_provider_settings')->where('id', $setting->id)->value('api_key_encrypted');
        $this->assertNotSame($afterBlankUpdate, $afterReplacement);
        $this->assertSame('replacement-key', $setting->fresh()->api_key_encrypted);
    }

    public function test_setting_default_clears_only_same_user_other_defaults(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $oldDefault = $this->createSetting($user, ['display_name' => 'Old', 'is_default' => true]);
        $newDefault = $this->createSetting($user, ['display_name' => 'New', 'is_default' => false]);
        $otherDefault = $this->createSetting($otherUser, ['display_name' => 'Other', 'is_default' => true]);

        $this->actingAs($user)
            ->patch("/settings/{$newDefault->id}/default")
            ->assertRedirect('/settings');

        $this->assertFalse($oldDefault->fresh()->is_default);
        $this->assertTrue($newDefault->fresh()->is_default);
        $this->assertTrue($otherDefault->fresh()->is_default);
        $this->assertSame(1, $user->llmProviderSettings()->where('is_default', true)->count());
    }

    public function test_database_prevents_multiple_defaults_for_same_user(): void
    {
        $user = User::factory()->create();

        $this->createSetting($user, [
            'display_name' => 'Default A',
            'is_default' => true,
            'default_marker' => true,
        ]);

        $this->expectException(QueryException::class);

        $this->createSetting($user, [
            'display_name' => 'Default B',
            'is_default' => true,
            'default_marker' => true,
        ]);
    }

    public function test_user_cannot_update_delete_or_set_default_for_another_users_setting(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherSetting = $this->createSetting($otherUser, [
            'display_name' => 'Other',
            'is_default' => false,
        ]);

        $this->actingAs($user)
            ->patch("/settings/{$otherSetting->id}", $this->validPayload(['display_name' => 'Stolen']))
            ->assertForbidden();

        $this->actingAs($user)
            ->patch("/settings/{$otherSetting->id}/default")
            ->assertForbidden();

        $this->actingAs($user)
            ->delete("/settings/{$otherSetting->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('llm_provider_settings', [
            'id' => $otherSetting->id,
            'user_id' => $otherUser->id,
            'display_name' => 'Other',
            'is_default' => false,
        ]);
    }

    public function test_user_can_delete_own_setting(): void
    {
        $user = User::factory()->create();
        $setting = $this->createSetting($user);

        $this->actingAs($user)
            ->delete("/settings/{$setting->id}")
            ->assertRedirect('/settings');

        $this->assertDatabaseMissing('llm_provider_settings', ['id' => $setting->id]);
    }

    public function test_validation_rejects_invalid_provider_type_base_url_and_ranges(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings')
            ->post('/settings', [
                'provider_type' => 'not-a-real-provider',
                'display_name' => '',
                'base_url' => 'not-a-url',
                'api_key' => str_repeat('x', 2049),
                'model' => '',
                'timeout_seconds' => 4,
                'temperature' => 2.01,
                'max_tokens' => 127,
                'is_default' => true,
            ])
            ->assertRedirect('/settings')
            ->assertInvalid([
                'provider_type',
                'display_name',
                'base_url',
                'api_key',
                'model',
                'timeout_seconds',
                'temperature',
                'max_tokens',
            ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'provider_type' => 'openai',
            'display_name' => 'Primary',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'secret-key',
            'model' => 'gpt-5',
            'timeout_seconds' => 60,
            'temperature' => 0.2,
            'max_tokens' => 1200,
            'is_default' => false,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSetting(User $user, array $overrides = []): LlmProviderSetting
    {
        return $user->llmProviderSettings()->create(array_merge([
            'provider_type' => 'openai',
            'display_name' => 'Primary',
            'base_url' => 'https://api.openai.com/v1',
            'api_key_encrypted' => 'secret-key',
            'model' => 'gpt-5',
            'timeout_seconds' => 60,
            'temperature' => '0.20',
            'max_tokens' => 1200,
            'is_default' => false,
        ], $overrides));
    }
}
