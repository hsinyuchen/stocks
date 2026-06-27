<?php

namespace Tests\Feature\Llm;

use App\Models\LlmProviderSetting;
use App\Models\User;
use App\Services\Llm\AnthropicLlmProvider;
use App\Services\Llm\GeminiLlmProvider;
use App\Services\Llm\LlmProviderFactory;
use App\Services\Llm\OpenAiCompatibleLlmProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LlmProviderFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_openai_compatible_provider_for_ollama(): void
    {
        $user = User::factory()->create();
        $setting = $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Local Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);

        $provider = (new LlmProviderFactory())->make($setting);

        $this->assertInstanceOf(OpenAiCompatibleLlmProvider::class, $provider);
    }

    public function test_builds_gemini_provider(): void
    {
        $user = User::factory()->create();
        $setting = $user->llmProviderSettings()->create([
            'provider_type' => 'gemini',
            'display_name' => 'Gemini',
            'base_url' => null,
            'api_key_encrypted' => 'secret-key',
            'model' => 'gemini-2.5-flash',
            'timeout_seconds' => 60,
            'temperature' => 0.20,
            'max_tokens' => 1200,
            'is_default' => true,
            'default_marker' => true,
        ]);

        $this->assertInstanceOf(GeminiLlmProvider::class, (new LlmProviderFactory())->make($setting));
    }

    public function test_builds_anthropic_provider_for_claude(): void
    {
        $user = User::factory()->create();
        $setting = $user->llmProviderSettings()->create([
            'provider_type' => 'anthropic',
            'display_name' => 'Claude',
            'base_url' => null,
            'api_key_encrypted' => 'sk-ant-key',
            'model' => 'claude-sonnet-4-6',
            'timeout_seconds' => 60,
            'temperature' => 0.20,
            'max_tokens' => 1200,
            'is_default' => true,
            'default_marker' => true,
        ]);

        $this->assertInstanceOf(AnthropicLlmProvider::class, (new LlmProviderFactory())->make($setting));
    }

    public function test_throws_when_openai_compatible_has_no_base_url(): void
    {
        $user = User::factory()->create();
        $setting = $user->llmProviderSettings()->create([
            'provider_type' => 'openai_compatible',
            'display_name' => 'Custom',
            'base_url' => null,
            'api_key_encrypted' => 'k',
            'model' => 'custom',
            'timeout_seconds' => 60,
            'temperature' => 0.20,
            'max_tokens' => 1200,
            'is_default' => true,
            'default_marker' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new LlmProviderFactory())->make($setting);
    }

    public function test_default_llm_setting_prefers_marked_default_then_first(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->defaultLlmSetting());

        $first = $user->llmProviderSettings()->create($this->settingAttributes('First', false));
        $this->assertTrue($user->defaultLlmSetting()->is($first));

        $second = $user->llmProviderSettings()->create($this->settingAttributes('Second', true));
        $this->assertTrue($user->fresh()->defaultLlmSetting()->is($second));
    }

    /** @return array<string, mixed> */
    private function settingAttributes(string $name, bool $default): array
    {
        return [
            'provider_type' => 'ollama',
            'display_name' => $name,
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => $default,
            'default_marker' => $default ? true : null,
        ];
    }
}
