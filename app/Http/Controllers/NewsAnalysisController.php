<?php

namespace App\Http\Controllers;

use App\Models\LlmProviderSetting;
use App\Models\NewsItem;
use App\Services\Llm\LlmProviderFactory;
use App\Services\News\NewsAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NewsAnalysisController extends Controller
{
    public function __construct(
        private readonly NewsAnalysisService $service,
        private readonly LlmProviderFactory $factory,
    ) {}

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
        $llm = $this->factory->make($setting);
        $result = $this->service->analyzeItem($newsItem, $llm, $model);

        $request->user()->newsAnalyses()->create([
            'news_item_id' => $newsItem->id,
            'type' => 'item',
            'provider_type' => (string) ($result['provider'] ?? 'unknown'),
            'model' => (string) ($result['model'] ?? $model),
            'prompt_version' => 'v1',
            'sentiment' => $result['sentiment'] ?? null,
            'impact_score' => $result['impact'] ?? null,
            'related_symbols' => $result['symbols'] ?? [],
            'summary' => $result['summary'] ?? null,
            'reasoning' => $result['reasoning'] ?? null,
            'raw_output' => $result['raw'] ?? [],
            'data_as_of' => $this->parseDataAsOf($result['data_as_of'] ?? null),
        ]);

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
        $llm = $this->factory->make($setting);

        $items = NewsItem::query()
            ->where('published_at', '>=', now()->subDay())
            ->orderByDesc('published_at')
            ->limit(30)
            ->get();

        if ($items->isEmpty()) {
            $items = NewsItem::query()
                ->orderByDesc('created_at')
                ->limit(30)
                ->get();
        }

        $result = $this->service->dailySummary($items->all(), $llm, $model);

        $request->user()->newsAnalyses()->create([
            'news_item_id' => null,
            'type' => 'daily_summary',
            'provider_type' => (string) ($result['provider'] ?? 'unknown'),
            'model' => (string) ($result['model'] ?? $model),
            'prompt_version' => 'v1',
            'sentiment' => null,
            'impact_score' => null,
            'related_symbols' => $result['symbols'] ?? [],
            'summary' => $result['summary'] ?? null,
            'reasoning' => null,
            'raw_output' => $result['raw'] ?? [],
            'data_as_of' => $this->parseDataAsOf($result['data_as_of'] ?? null),
        ]);

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

    private function parseDataAsOf(mixed $value): CarbonImmutable
    {
        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return CarbonImmutable::now();
    }
}
