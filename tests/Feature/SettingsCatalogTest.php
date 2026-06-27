<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function brands(): array
    {
        return [
            'deepseek' => ['deepseek', 'deepseek-chat'],
            'anthropic' => ['anthropic', 'claude-sonnet-4-6'],
            'lmstudio' => ['lmstudio', 'local-model'],
            'zeabur' => ['zeabur', 'gpt-4.1-mini'],
        ];
    }

    #[DataProvider('brands')]
    public function test_user_can_save_each_new_brand(string $providerType, string $model): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings', [
                'provider_type' => $providerType,
                'display_name' => ucfirst($providerType),
                'base_url' => $providerType === 'zeabur' ? 'https://my-gateway.zeabur.app/v1' : '',
                'api_key' => $providerType === 'lmstudio' ? '' : 'secret-key',
                'model' => $model,
                'timeout_seconds' => 60,
                'temperature' => 0.2,
                'max_tokens' => 1200,
                'is_default' => true,
            ])
            ->assertRedirect('/settings');

        $this->assertDatabaseHas('llm_provider_settings', [
            'user_id' => $user->id,
            'provider_type' => $providerType,
            'model' => $model,
        ]);
    }
}
