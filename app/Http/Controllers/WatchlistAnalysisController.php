<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Jobs\RunWatchlistAnalysis;
use App\Models\Instrument;
use App\Models\LlmProviderSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WatchlistAnalysisController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        // 輪詢走精簡路徑：只重抓 analysis，跳過自選清單彙總與模型清單的查詢。
        if ($this->isPollOnly($request)) {
            return Inertia::render('Watchlists/Analysis', [
                'analysis' => $this->analysisPayload($user),
            ]);
        }

        return Inertia::render('Watchlists/Analysis', [
            'analysis' => $this->analysisPayload($user),
            'llmProviders' => $this->llmProviders($user),
            'watchlistSummary' => $this->watchlistSummary($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:120'],
            'llm_provider_setting_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $locale = $user->profile?->locale ?? 'zh';
        $setting = $this->resolveSetting($user, $data['llm_provider_setting_id'] ?? null);

        if ($setting === null) {
            return redirect()->back()->with('error', $locale === 'en'
                ? 'Please add an AI model in Settings first.'
                : '請先在設定新增 AI 模型。');
        }

        if (! $this->hasWatchlistInstruments($user)) {
            return redirect()->back()->with('error', $locale === 'en'
                ? 'Your watchlist is empty. Add stocks before generating the evening briefing.'
                : '自選清單是空的，請先加入股票再產生晚間快報。');
        }

        $model = trim((string) ($data['model'] ?? '')) ?: (string) $setting->model;

        // 只落地骨架就回應：多檔行情抓取加 LLM 呼叫可能耗上數分鐘，留在 request
        // 內會讓整站停止回應。自選股查詢與內容補完都交給 RunWatchlistAnalysis。
        $analysis = $user->watchlistAnalyses()->create([
            'provider_type' => 'pending',
            'model' => $model,
            'prompt_version' => (string) config('brief.prompt_version', 'v1'),
            'status' => AnalysisStatus::Pending,
            'related_symbols' => [],
            'payload' => [],
            'raw_output' => [],
            'data_as_of' => CarbonImmutable::now(),
        ]);

        RunWatchlistAnalysis::dispatch($analysis->id, $setting->id, $model, $locale);

        return redirect()->route('watchlists.analysis');
    }

    private function resolveSetting(User $user, ?int $settingId): ?LlmProviderSetting
    {
        if ($settingId === null) {
            return $user->defaultLlmSetting();
        }

        $setting = $user->llmProviderSettings()->whereKey($settingId)->first();
        abort_if($setting === null, 403);

        return $setting;
    }

    private function hasWatchlistInstruments(User $user): bool
    {
        return Instrument::query()
            ->whereHas('watchlistItems', function (Builder $inner) use ($user): void {
                $inner->whereHas('watchlist', fn (Builder $w) => $w->where('user_id', $user->id));
            })
            ->exists();
    }

    /**
     * 自選清單彙總：合併去重後的檔數與代號預覽，供頁面顯示「將分析哪些標的」。
     *
     * @return array<string, mixed>
     */
    private function watchlistSummary(User $user): array
    {
        $limit = max(1, (int) config('brief.watchlist_limit', 30));

        $symbols = Instrument::query()
            ->whereHas('watchlistItems', function (Builder $inner) use ($user): void {
                $inner->whereHas('watchlist', fn (Builder $w) => $w->where('user_id', $user->id));
            })
            ->orderBy('symbol')
            ->pluck('symbol')
            ->all();

        return [
            'count' => count($symbols),
            'limit' => $limit,
            'symbols' => array_slice($symbols, 0, $limit),
            'omitted' => max(0, count($symbols) - $limit),
        ];
    }

    private function analysisPayload(User $user): ?array
    {
        $analysis = $user->watchlistAnalyses()->latest('id')->first();

        if ($analysis === null) {
            return null;
        }

        $raw = $analysis->raw_output ?? [];

        return [
            'id' => $analysis->id,
            'status' => $analysis->status->value,
            // 失敗原因存在 raw_output，讓畫面能說清楚是逾時、金鑰失效還是模型名錯。
            'failure' => $raw['failure'] ?? null,
            'provider_type' => $analysis->provider_type,
            'model' => $analysis->model,
            'summary' => $analysis->summary,
            'points' => array_values((array) ($raw['points'] ?? [])),
            'related_symbols' => array_values($analysis->related_symbols ?? []),
            'payload' => $analysis->payload ?? ['background' => [], 'futures' => null, 'stocks' => [], 'omitted' => 0],
            'data_as_of' => $analysis->data_as_of?->toIso8601String(),
            'created_at' => $analysis->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function llmProviders(User $user): array
    {
        return $user->llmProviderSettings()
            ->orderByDesc('is_default')
            ->orderBy('display_name')
            ->get()
            ->map(fn (LlmProviderSetting $setting): array => [
                'id' => $setting->id,
                'display_name' => $setting->display_name,
                'provider_type' => $setting->provider_type,
                'model' => $setting->model,
                'is_default' => (bool) $setting->is_default,
            ])
            ->values()
            ->all();
    }

    /**
     * 這次請求是不是只為了輪詢分析狀態。比對「請求的 props 是否完全落在 analysis
     * 之內」而非只看標頭，日後頁面若出現其他部分重載不會被精簡分支吞掉。
     */
    private function isPollOnly(Request $request): bool
    {
        $partial = array_filter(array_map(
            'trim',
            explode(',', (string) $request->header('X-Inertia-Partial-Data')),
        ));

        return $partial !== [] && array_diff($partial, ['analysis']) === [];
    }
}
