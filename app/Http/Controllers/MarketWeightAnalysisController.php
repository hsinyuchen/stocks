<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Jobs\RunMarketWeightAnalysis;
use App\Models\LlmProviderSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketWeightAnalysisController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        // 輪詢走精簡路徑：只重抓 analysis，跳過籃子摘要與模型清單的查詢。
        if ($this->isPollOnly($request)) {
            return Inertia::render('Market/WeightAnalysis', [
                'analysis' => $this->analysisPayload($user),
            ]);
        }

        return Inertia::render('Market/WeightAnalysis', [
            'analysis' => $this->analysisPayload($user),
            'llmProviders' => $this->llmProviders($user),
            'basketSummary' => $this->basketSummary(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:120'],
            'llm_provider_setting_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $setting = $this->resolveSetting($user, $data['llm_provider_setting_id'] ?? null);

        if ($setting === null) {
            return redirect()->back()->with('error', '請先在設定新增 AI 模型。');
        }

        $model = trim((string) ($data['model'] ?? '')) ?: (string) $setting->model;

        // 只落地骨架就回應：多檔行情抓取加 LLM 呼叫可能耗上數分鐘，留在 request
        // 內會讓整站停止回應。籃子讀取與內容補完都交給 RunMarketWeightAnalysis。
        $analysis = $user->marketWeightAnalyses()->create([
            'provider_type' => 'pending',
            'model' => $model,
            'prompt_version' => (string) config('weight_basket.prompt_version', 'v1'),
            'status' => AnalysisStatus::Pending,
            'related_symbols' => [],
            'payload' => [],
            'raw_output' => [],
            'data_as_of' => CarbonImmutable::now(),
        ]);

        RunMarketWeightAnalysis::dispatch($analysis->id, $setting->id, $model);

        return redirect()->route('market.weight-analysis');
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

    /**
     * 籃子預覽：權值股檔數、權重更新日與代號預覽，供頁面顯示「將分析哪些標的」。
     *
     * @return array<string, mixed>
     */
    private function basketSummary(): array
    {
        $limit = max(1, (int) config('weight_basket.limit', 15));
        $entries = array_slice((array) config('weight_basket.symbols', []), 0, $limit);

        return [
            'count' => count($entries),
            'limit' => $limit,
            'weights_as_of' => (string) config('weight_basket.weights_as_of', ''),
            'symbols' => array_values(array_map(
                static fn (array $e): array => [
                    'symbol' => (string) ($e['symbol'] ?? ''),
                    'name' => (string) ($e['name'] ?? ''),
                    'weight' => is_numeric($e['weight'] ?? null) ? (float) $e['weight'] : null,
                ],
                $entries,
            )),
        ];
    }

    private function analysisPayload(User $user): ?array
    {
        $analysis = $user->marketWeightAnalyses()->latest('id')->first();

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
            'payload' => $analysis->payload ?? [
                'weights_as_of' => '',
                'benchmarks' => [],
                'futures' => null,
                'stocks' => [],
                'aggregate' => null,
            ],
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
