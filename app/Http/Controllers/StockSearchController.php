<?php

namespace App\Http\Controllers;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Data\ChipFlowData;
use App\Data\MarginFlowData;
use App\Enums\AnalysisStatus;
use App\Enums\AssetType;
use App\Enums\MarketRegion;
use App\Jobs\RunStockAnalysis;
use App\Models\Instrument;
use App\Models\LlmProviderSetting;
use App\Models\StockAnalysis;
use App\Models\User;
use App\Services\Chip\ChipDataService;
use App\Services\Fundamentals\FundamentalsService;
use App\Services\Margin\MarginDataService;
use App\Services\News\SymbolNewsService;
use App\Services\Search\StockSearchService;
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
        $instrument = $this->findOrCreateInstrumentFromQuote($quote, $name !== '' ? $name : null);

        // 個股新聞新鮮度觸發（best-effort、有節流），讓同請求內的
        // relatedNews 直接讀到新資料。refreshIfStale 自身已 try/catch，不擋頁面。
        app(SymbolNewsService::class)->refreshIfStale($instrument);

        $news = $this->news->relatedNews($symbol, 5);

        // 台股才有基本面（best-effort，service 內已容錯且有快取節流）。
        $fundamentalsService = app(FundamentalsService::class);
        $fundamentals = $fundamentalsService->forInstrument($instrument);
        // 分位需先累積足夠觀測日，樣本不足時為 null（前端不顯示該區塊）。
        $valuation = $fundamentals === null ? null : $fundamentalsService->valuationPercentiles($instrument);

        // 台股才有籌碼。頁面只顯示最近 20 個交易日，快取本身仍保留較長歷史。
        $chipFlows = app(ChipDataService::class)->forInstrument($instrument);
        // 融資融券同為台股限定，且與籌碼共用 FinMind 額度。
        $marginFlows = app(MarginDataService::class)->forInstrument($instrument);

        // 首載瘦身：不再輸出 prices/indicators，前端掛載後另打 stocks.chart endpoint
        // 取 5 年日/週/月 K 與完整指標序列，避免首頁 payload 過大。
        return Inertia::render('Stocks/Search', [
            'symbol' => $instrument->symbol,
            'instrument' => $this->instrumentPayload($instrument),
            'quote' => $this->quotePayload($quote),
            'news' => array_map(fn (object $item): array => $this->newsPayload($item), $news),
            'analyses' => $this->analysisPayload($request, $instrument),
            'llmProviders' => $this->llmProvidersPayload($request),
            'fundamentals' => $fundamentals === null ? null : [
                'per' => $fundamentals->per, 'pbr' => $fundamentals->pbr, 'dividend_yield' => $fundamentals->dividendYield,
                'eps' => $fundamentals->eps, 'eps_quarter' => $fundamentals->epsQuarter, 'roe' => $fundamentals->roe,
                'revenue' => $fundamentals->revenue, 'revenue_month' => $fundamentals->revenueMonth, 'revenue_yoy' => $fundamentals->revenueYoy,
                'data_as_of' => $fundamentals->dataAsOf,
                'percentiles' => $valuation,
            ],
            'chipFlows' => array_map(fn (ChipFlowData $flow): array => [
                'date' => $flow->date,
                'foreign_net' => $flow->foreignNet,
                'trust_net' => $flow->trustNet,
                'dealer_net' => $flow->dealerNet,
                'total_net' => $flow->totalNet,
            ], array_slice($chipFlows, -20)),
            // 使用率與券資比由 DTO 算好再送出：分母（融資限額、融資餘額）可能為 0，
            // 除法的邊界處理只做一次，前端不必重複判斷。
            'marginFlows' => array_map(fn (MarginFlowData $flow): array => [
                'date' => $flow->date,
                'margin_balance' => $flow->marginBalance,
                'margin_change' => $flow->marginChange,
                'margin_limit' => $flow->marginLimit,
                'short_balance' => $flow->shortBalance,
                'short_change' => $flow->shortChange,
                'offset' => $flow->offsetLoanAndShort,
                'usage_percent' => $flow->marginUsagePercent(),
                'short_ratio' => $flow->shortToMarginPercent(),
            ], array_slice($marginFlows, -20)),
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

        // 只落地骨架就回應：行情抓取與 LLM 呼叫加起來可能耗上數分鐘，留在 request
        // 內會讓整個站台停止回應。內容由 RunStockAnalysis 補完，前端輪詢 status。
        $analysis = $user->stockAnalyses()->create([
            'instrument_id' => $instrument->id,
            'technical_snapshot_id' => null,
            'provider_type' => 'pending',
            'model' => $model,
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'rule_signal' => [],
            'llm_output' => [],
            'data_as_of' => CarbonImmutable::now(),
        ]);

        RunStockAnalysis::dispatch($analysis->id, $setting?->id, $model);

        return redirect()->route('stocks.search', ['symbol' => $instrument->symbol]);
    }

    /**
     * 刪除單筆參考分析。
     *
     * 失敗的分析（上游逾時、金鑰失效）會在歷史裡累積成沒有內容的雜訊，且每檔只
     * 顯示最近 5 筆，廢資料會把真正有用的分析擠掉，所以要能逐筆清掉。
     */
    public function destroyAnalysis(Request $request, StockAnalysis $stockAnalysis): RedirectResponse
    {
        abort_unless($stockAnalysis->user_id === $request->user()->id, 403);

        $symbol = $stockAnalysis->instrument?->symbol;

        $stockAnalysis->delete();

        // 刪完留在原本那檔股票的頁面；查不到標的（理論上不會）才退回搜尋首頁。
        return $symbol === null
            ? redirect()->route('stocks.search')
            : redirect()->route('stocks.search', ['symbol' => $symbol]);
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

    private function emptyPayload(): array
    {
        return [
            'symbol' => null,
            'instrument' => null,
            'quote' => null,
            'news' => [],
            'analyses' => [],
            'llmProviders' => [],
            'chipFlows' => [],
            'marginFlows' => [],
        ];
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

    private function newsPayload(object $item): array
    {
        return [
            'source' => $item->source,
            'title' => $item->title,
            'summary' => $item->summary,
            'topic' => $item->topic,
            // 供前端連回原文。DTO 預設空字串（fake provider、無來源連結的舊資料），
            // 前端據此決定是否渲染連結。
            'url' => $item->url,
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
                'status' => $analysis->status->value,
                'rule_signal' => $analysis->rule_signal,
                'llm_output' => $analysis->llm_output,
                'data_as_of' => $analysis->data_as_of?->toIso8601String(),
                'created_at' => $analysis->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
