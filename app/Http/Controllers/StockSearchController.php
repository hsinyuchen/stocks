<?php

namespace App\Http\Controllers;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Enums\AssetType;
use App\Enums\MarketRegion;
use App\Models\Instrument;
use App\Models\StockAnalysis;
use App\Services\StockAnalysisService;
use Carbon\CarbonImmutable;
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
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        if (! $request->has('symbol')) {
            return Inertia::render('Stocks/Search', $this->emptyPayload());
        }

        $this->normalizeSymbolInput($request);

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9.\-]+$/'],
        ]);

        $symbol = $data['symbol'];
        $quote = $this->marketData->quote($symbol);
        $prices = $this->marketData->dailyPrices($symbol, 20);
        $news = $this->news->relatedNews($symbol, 5);
        $instrument = $this->findOrCreateInstrumentFromQuote($quote);

        return Inertia::render('Stocks/Search', [
            'symbol' => $instrument->symbol,
            'instrument' => $this->instrumentPayload($instrument),
            'quote' => $this->quotePayload($quote),
            'prices' => array_map(fn (object $price): array => $this->pricePayload($price), $prices),
            'news' => array_map(fn (object $item): array => $this->newsPayload($item), $news),
            'analyses' => $this->analysisPayload($request, $instrument),
        ]);
    }

    public function analyze(Request $request, Instrument $instrument): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:120'],
        ]);

        $model = trim((string) ($data['model'] ?? '')) ?: 'reference-model';
        $result = $this->stockAnalysis->analyze($instrument->symbol, $model);

        $request->user()->stockAnalyses()->create([
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

    private function emptyPayload(): array
    {
        return [
            'symbol' => null,
            'instrument' => null,
            'quote' => null,
            'prices' => [],
            'news' => [],
            'analyses' => [],
        ];
    }

    private function normalizeSymbolInput(Request $request): void
    {
        $request->merge([
            'symbol' => strtoupper(trim((string) $request->query('symbol', ''))),
        ]);
    }

    private function findOrCreateInstrumentFromQuote(object $quote): Instrument
    {
        $symbol = strtoupper(trim((string) $quote->symbol));
        $market = str_ends_with($symbol, '.TW') ? MarketRegion::Taiwan : MarketRegion::UnitedStates;

        return Instrument::query()->createOrFirst(
            ['symbol' => $symbol],
            [
                'name' => $symbol,
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
