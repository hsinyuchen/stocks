<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Jobs\RunNewsDailySummary;
use App\Jobs\RunNewsItemAnalysis;
use App\Models\LlmProviderSetting;
use App\Models\NewsItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NewsAnalysisController extends Controller
{
    public function store(Request $request, NewsItem $newsItem): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:120'],
            'llm_provider_setting_id' => ['nullable', 'integer'],
        ]);

        $setting = $this->resolveSetting($request, $data);

        if ($setting === null) {
            return redirect()->back()->with('error', '請先在設定新增 AI 模型。');
        }

        $model = trim((string) ($data['model'] ?? '')) ?: (string) $setting->model;

        // 只落地骨架就回應，LLM 呼叫交給 RunNewsItemAnalysis：實測單則分析要 47 秒，
        // 上游塞住時更會卡滿 120 秒逾時，留在 request 內等於讓整站停擺。
        $analysis = $request->user()->newsAnalyses()->create([
            'news_item_id' => $newsItem->id,
            'type' => 'item',
            'provider_type' => 'pending',
            'model' => $model,
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'related_symbols' => [],
            'raw_output' => [],
            'data_as_of' => CarbonImmutable::now(),
        ]);

        RunNewsItemAnalysis::dispatch($analysis->id, $setting->id, $model);

        return redirect()->back();
    }

    public function dailySummary(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:120'],
            'llm_provider_setting_id' => ['nullable', 'integer'],
        ]);

        $setting = $this->resolveSetting($request, $data);

        if ($setting === null) {
            return redirect()->back()->with('error', '請先在設定新增 AI 模型。');
        }

        $model = trim((string) ($data['model'] ?? '')) ?: (string) $setting->model;

        // 候選新聞的查詢與聚類都搬進 job：這是最重的一種分析，prompt 涵蓋數十個
        // 事件，同步跑必定拖垮 request。
        $analysis = $request->user()->newsAnalyses()->create([
            'news_item_id' => null,
            'type' => 'daily_summary',
            'provider_type' => 'pending',
            'model' => $model,
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'sentiment' => null,
            'impact_score' => null,
            'related_symbols' => [],
            'reasoning' => null,
            'raw_output' => [],
            'data_as_of' => CarbonImmutable::now(),
        ]);

        RunNewsDailySummary::dispatch($analysis->id, $setting->id, $model);

        return redirect()->back();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSetting(Request $request, array $data): ?LlmProviderSetting
    {
        $user = $request->user();
        $settingId = $data['llm_provider_setting_id'] ?? null;

        if ($settingId !== null) {
            $setting = $user->llmProviderSettings()->whereKey($settingId)->first();
            abort_if($setting === null, 403);

            return $setting;
        }

        return $user->defaultLlmSetting();
    }
}
