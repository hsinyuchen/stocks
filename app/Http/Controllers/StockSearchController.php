<?php

namespace App\Http\Controllers;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Enums\AssetType;
use App\Enums\MarketRegion;
use App\Models\Instrument;
use App\Models\LlmProviderSetting;
use App\Models\StockAnalysis;
use App\Services\Llm\LlmProviderFactory;
use App\Services\Search\StockSearchService;
use App\Services\StockAnalysisService;
use App\Services\TechnicalIndicatorService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockSearchController extends Controller
{
    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly NewsProvider $news,
        private readonly StockAnalysisService $stockAnalysis,
        private readonly LlmProviderFactory $llmFactory,
        private readonly TechnicalIndicatorService $indicators,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        if (! $request->has('symbol')) {
            return Inertia::render('Stocks/Search', [
                ...$this->emptyPayload(),
                'llmProviders' => $this->llmProvidersPayload($request),
            ]);
        }

        $this->normalizeSymbolInput($request);

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9.\-]+$/'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $symbol = $data['symbol'];
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        $quote = $this->marketData->quote($symbol);
        $history = $this->marketData->dailyPrices($symbol, 120);
        $prices = array_slice($history, -20);
        $news = $this->news->relatedNews($symbol, 5);
        $instrument = $this->findOrCreateInstrumentFromQuote($quote, $name !== '' ? $name : null);

        return Inertia::render('Stocks/Search', [
            'symbol' => $instrument->symbol,
            'instrument' => $this->instrumentPayload($instrument),
            'quote' => $this->quotePayload($quote),
            'prices' => array_map(fn (object $price): array => $this->pricePayload($price), $prices),
            'indicators' => $this->indicatorsPayload($history),
            'news' => array_map(fn (object $item): array => $this->newsPayload($item), $news),
            'analyses' => $this->analysisPayload($request, $instrument),
            'llmProviders' => $this->llmProvidersPayload($request),
        ]);
    }

    public function lookup(Request $request, StockSearchService $service): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'market' => ['required', 'in:tw,us'],
        ]);

        $results = $service->search(
            (string) $request->query('q', ''),
            (string) $request->query('market'),
        );

        return response()->json(['results' => $results]);
    }

    public function analyze(Request $request, Instrument $instrument): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:120'],
            'llm_provider_setting_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $setting = $this->resolveSetting($user, $data['llm_provider_setting_id'] ?? null);
        $model = trim((string) ($data['model'] ?? '')) ?: ($setting->model ?? 'reference-model');

        $result = $setting !== null
            ? $this->stockAnalysis->analyze($instrument->symbol, $model, $this->llmFactory->make($setting))
            : $this->stockAnalysis->analyze($instrument->symbol, $model);

        $user->stockAnalyses()->create([
            'instrument_id' => $instrument->id,
            'technical_snapshot_id' => null,
            'provider_type' => (string) ($result['llm']['provider'] ?? 'unknown'),
            'model' => (string) ($result['llm']['model'] ?? $model),
            'prompt_version' => 'v1',
            'rule_signal' => $result['rule_signal'] ?? [],
            'llm_output' => $result['llm'] ?? [],
            'data_as_of' => CarbonImmutable::parse($result['data_as_of']),
        ]);

        return redirect()->route('stocks.search', ['symbol' => $instrument->symbol]);
    }

    private function resolveSetting(\App\Models\User $user, ?int $settingId): ?LlmProviderSetting
    {
        if ($settingId === null) {
            return $user->defaultLlmSetting();
        }

        $setting = $user->llmProviderSettings()->whereKey($settingId)->first();
        abort_if($setting === null, 403);

        return $setting;
    }

    private function emptyPayload(): array
    {
        return [
            'symbol' => null,
            'instrument' => null,
            'quote' => null,
            'prices' => [],
            'indicators' => null,
            'news' => [],
            'analyses' => [],
            'llmProviders' => [],
        ];
    }

    /**
     * Indicator series for charting, computed over the full warmup history and
     * trimmed to the last 60 entries (each array sliced consistently).
     *
     * @param  list<object>  $history
     */
    private function indicatorsPayload(array $history): ?array
    {
        if ($history === []) {
            return null;
        }

        $series = $this->indicators->series($history);

        return array_map(
            fn ($values) => is_array($values) ? array_values(array_slice($values, -60)) : $values,
            $series,
        );
    }

    private function llmProvidersPayload(Request $request): array
    {
        return $request->user()
            ->llmProviderSettings()
            ->orderByDesc('is_default')
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'provider_type', 'model', 'is_default'])
            ->map(fn (LlmProviderSetting $setting): array => [
                'id' => $setting->id,
                'display_name' => $setting->display_name,
                'provider_type' => $setting->provider_type,
                'model' => $setting->model,
                'is_default' => $setting->is_default,
            ])
            ->values()
            ->all();
    }

    private function normalizeSymbolInput(Request $request): void
    {
        $request->merge([
            'symbol' => strtoupper(trim((string) $request->query('symbol', ''))),
        ]);
    }

    private function findOrCreateInstrumentFromQuote(object $quote, ?string $name = null): Instrument
    {
        $symbol = strtoupper(trim((string) $quote->symbol));
        $market = str_ends_with($symbol, '.TW') || str_ends_with($symbol, '.TWO')
            ? MarketRegion::Taiwan
            : MarketRegion::UnitedStates;

        return Instrument::query()->createOrFirst(
            ['symbol' => $symbol],
            [
                'name' => $name ?? $symbol,
                'market' => $market,
                'asset_type' => AssetType::Stock,
                'currency' => $market === MarketRegion::Taiwan ? 'TWD' : 'USD',
                'exchange' => null,
            ],
        );
    }

    private function instrumentPayload(Instrument $instrument): array
    {
        return [
            'id' => $instrument->id,
            'symbol' => $instrument->symbol,
            'name' => $instrument->name,
            'market' => $instrument->market->value,
            'asset_type' => $instrument->asset_type->value,
            'currency' => $instrument->currency,
            'exchange' => $instrument->exchange,
        ];
    }

    private function quotePayload(object $quote): array
    {
        return [
            'symbol' => strtoupper(trim((string) $quote->symbol)),
            'price' => $quote->price,
            'change' => $quote->change,
            'change_percent' => $quote->changePercent,
            'as_of' => $quote->asOf,
        ];
    }

    private function pricePayload(object $price): array
    {
        return [
            'date' => $price->date,
            'open' => $price->open,
            'high' => $price->high,
            'low' => $price->low,
            'close' => $price->close,
            'volume' => $price->volume,
        ];
    }

    private function newsPayload(object $item): array
    {
        return [
            'source' => $item->source,
            'title' => $item->title,
            'summary' => $item->summary,
            'topic' => $item->topic,
            'related_symbols' => $item->relatedSymbols,
            'published_at' => $item->publishedAt,
        ];
    }

    private function analysisPayload(Request $request, Instrument $instrument): array
    {
        return $request->user()
            ->stockAnalyses()
            ->where('instrument_id', $instrument->id)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (StockAnalysis $analysis): array => [
                'id' => $analysis->id,
                'provider_type' => $analysis->provider_type,
                'model' => $analysis->model,
                'prompt_version' => $analysis->prompt_version,
                'rule_signal' => $analysis->rule_signal,
                'llm_output' => $analysis->llm_output,
                'data_as_of' => $analysis->data_as_of?->toIso8601String(),
                'created_at' => $analysis->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
