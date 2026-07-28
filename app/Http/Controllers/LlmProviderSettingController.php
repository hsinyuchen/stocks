<?php

namespace App\Http\Controllers;

use App\Enums\LlmProviderType;
use App\Models\LlmProviderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LlmProviderSettingController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = $request->user()
            ->llmProviderSettings()
            ->orderByDesc('is_default')
            ->orderBy('display_name')
            ->get()
            ->map(fn (LlmProviderSetting $setting): array => [
                'id' => $setting->id,
                'provider_type' => $setting->provider_type,
                'display_name' => $setting->display_name,
                'base_url' => $setting->base_url,
                'model' => $setting->model,
                'timeout_seconds' => $setting->timeout_seconds,
                'temperature' => (float) $setting->temperature,
                'max_tokens' => $setting->max_tokens,
                'is_default' => $setting->is_default,
                'has_api_key' => filled($setting->getRawOriginal('api_key_encrypted')),
                'created_at' => $setting->created_at?->toIso8601String(),
                'updated_at' => $setting->updated_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeInput($request);
        $data = $this->validatedData($request);

        DB::transaction(function () use ($request, $data): void {
            if ($data['is_default']) {
                $this->clearOtherDefaults($request);
            }

            $request->user()->llmProviderSettings()->create($this->settingAttributes($data));
        });

        return redirect()->route('settings.index');
    }

    public function update(Request $request, LlmProviderSetting $llmProviderSetting): RedirectResponse
    {
        $this->authorizeSetting($request, $llmProviderSetting);
        $this->normalizeInput($request);
        $data = $this->validatedData($request);

        DB::transaction(function () use ($request, $llmProviderSetting, $data): void {
            if ($data['is_default']) {
                $this->clearOtherDefaults($request, $llmProviderSetting);
            }

            $llmProviderSetting->update($this->settingAttributes($data, $llmProviderSetting));
        });

        return redirect()->route('settings.index');
    }

    public function destroy(Request $request, LlmProviderSetting $llmProviderSetting): RedirectResponse
    {
        $this->authorizeSetting($request, $llmProviderSetting);

        $llmProviderSetting->delete();

        return redirect()->route('settings.index');
    }

    public function makeDefault(Request $request, LlmProviderSetting $llmProviderSetting): RedirectResponse
    {
        $this->authorizeSetting($request, $llmProviderSetting);

        DB::transaction(function () use ($request, $llmProviderSetting): void {
            $this->clearOtherDefaults($request, $llmProviderSetting);
            $llmProviderSetting->update(['is_default' => true]);
        });

        return redirect()->route('settings.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        return $request->validate([
            'provider_type' => ['required', Rule::enum(LlmProviderType::class)],
            'display_name' => ['required', 'string', 'max:80'],
            // 限定 http/https：base_url 是使用者可控且會被伺服器主動請求的位址，
            // 裸 `url` rule 會放行 file://、gopher:// 等 scheme。私有網段不阻擋，
            // 因為 ollama / llamacpp / lmstudio 的預設端點本來就是 loopback。
            'base_url' => ['nullable', 'url:http,https', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:2048'],
            'model' => ['required', 'string', 'max:120'],
            'timeout_seconds' => ['required', 'integer', 'min:5', 'max:300'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['required', 'integer', 'min:128', 'max:32000'],
            'is_default' => ['required', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function settingAttributes(array $data, ?LlmProviderSetting $existing = null): array
    {
        $attributes = [
            'provider_type' => $data['provider_type'],
            'display_name' => $data['display_name'],
            'base_url' => $data['base_url'] ?? null,
            'model' => $data['model'],
            'timeout_seconds' => $data['timeout_seconds'],
            'temperature' => $data['temperature'],
            'max_tokens' => $data['max_tokens'],
            'is_default' => $data['is_default'],
            'default_marker' => $data['is_default'] ? true : null,
        ];

        if ($existing === null || filled($data['api_key'] ?? null)) {
            $attributes['api_key_encrypted'] = $data['api_key'] ?? null;
        }

        return $attributes;
    }

    private function clearOtherDefaults(Request $request, ?LlmProviderSetting $except = null): void
    {
        $query = $request->user()->llmProviderSettings();

        if ($except !== null) {
            $query->whereKeyNot($except->id);
        }

        $query->update([
            'is_default' => false,
            'default_marker' => null,
        ]);
    }

    private function authorizeSetting(Request $request, LlmProviderSetting $setting): void
    {
        abort_unless($setting->user_id === $request->user()->id, 403);
    }

    private function normalizeInput(Request $request): void
    {
        $request->merge([
            'provider_type' => trim((string) $request->input('provider_type', '')),
            'display_name' => trim((string) $request->input('display_name', '')),
            'base_url' => $this->nullableTrim($request->input('base_url')),
            'api_key' => $this->nullableTrim($request->input('api_key')),
            'model' => trim((string) $request->input('model', '')),
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
