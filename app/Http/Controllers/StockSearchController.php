<?php

namespace App\Http\Controllers;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Data\ChipFlowData;
use App\Data\HealthInputSnapshot;
use App\Data\IndustryMomentum;
use App\Data\MarginFlowData;
use App\Data\SocialArbitrage;
use App\Enums\AnalysisStatus;
use App\Enums\AssetType;
use App\Enums\MarketRegion;
use App\Jobs\RunStockAnalysis;
use App\Models\Instrument;
use App\Models\LlmProviderSetting;
use App\Models\StockAnalysis;
use App\Models\StockChatTurn;
use App\Models\User;
use App\Services\Analysis\SocialArbitrageGuide;
use App\Services\Analysis\SymbolContextService;
use App\Services\BrokerBranch\BrokerBranchDataService;
use App\Services\Chip\ChipDataService;
use App\Services\Fundamentals\FundamentalsService;
use App\Services\Fundamentals\IndustryMomentumSampler;
use App\Services\Health\HealthSnapshotBuilder;
use App\Services\Health\LongTermHealthReader;
use App\Services\Health\ShortTermHealthReader;
use App\Services\Margin\MarginDataService;
use App\Services\News\SymbolNewsService;
use App\Services\Search\StockSearchService;
use App\Services\Social\SocialArbitrageAssessor;
use App\Support\SocialArbitrageVerdicts;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockSearchController extends Controller
{
    /** 個股頁顯示的問答輪數。 */
    private const CHAT_TURNS_ON_PAGE = 12;

    /**
     * 體質判讀採計的 K 棒數，與 {@see SymbolContextService} 送進 prompt 的視窗
     * 相同（80 根）。
     *
     * **視窗必須一致，否則同一檔在頁面與報告裡會有兩個技術立場**：KD 從輸入序列
     * 第一根以 50 播種，視窗長度不同足以讓尾值跨過門檻（見
     * {@see HealthInputSnapshot} 的 docblock）。首載不再輸出 prices/indicators
     * 之後這裡是個股頁唯一取用 K 棒的地方，而 `cachedFor()` 直接讀
     * `daily_prices`，多取 80 列不會多打一次上游。
     */
    public const HEALTH_BARS = 80;

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

        // 輪詢走精簡路徑。Inertia 的 only 只縮小回傳的 props，不會跳過這個方法
        // 裡的任何 PHP——沒有這個分支的話，每 3 秒的輪詢都會重跑報價、新聞刷新、
        // 基本面、估值分位、籌碼與融資（其中數個在資料過期時還會打 FinMind）。
        if ($this->isPollOnly($request) && ($instrument = $this->findBySymbol($symbol)) !== null) {
            return Inertia::render('Stocks/Search', [
                'symbol' => $instrument->symbol,
                'analyses' => $this->analysisPayload($request, $instrument),
                'chatTurns' => $this->chatPayload($request, $instrument),
            ]);
        }

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
        // 券商分點主力摘要（Sponsor 付費）。middleware 已將 resolver 設為當前使用者 token，
        // Sponsor 使用者抓得到，免費 token 回 null → 前端面板顯示需贊助等級。
        $brokerBranch = app(BrokerBranchDataService::class)->summaryFor($instrument);

        // 社交套利與產業動能。兩者都是**全程只讀**的入口（見各自 docblock），一次
        // 上游都不打，所以可以留在這個同步 web 請求裡；換成會抓資料的入口
        // （OrderInventoryAssessor::forInstrument()、IndustryMomentumSampler::forInstrument()）
        // 會讓個股頁受 PHP max_execution_time 擺布，而那不是例外、try/catch 攔不到。
        $arbitrage = app(SocialArbitrageAssessor::class)->forInstrument($instrument);
        $momentum = app(IndustryMomentumSampler::class)->cachedFor($instrument);

        // 體質判讀，同樣只讀（見 healthPayload()）。
        //
        // **先落到區域變數，不要寫成 `'health' => $this->healthPayload($instrument)`。**
        // 那個寫法會讓本機的 PHP 8.4.8（Windows）在 `StockChatTest` 跑到個股頁那一
        // 條時穩定 segfault（實測 0/8 通過，改成區域變數後 8/8 通過，同期未加本功能
        // 的 HEAD 是 5/8——那台機器本來就有間歇性 segfault）。崩潰點不在本方法內：
        // 加一行 `fwrite(STDERR, ...)` 或改 `--order-by=reverse` 就不再重現，是引擎
        // 層的記憶體問題而不是這裡的邏輯。上面幾個較重的 payload 本來也都先落到區域
        // 變數，這樣寫同時也與它們一致。
        $health = $this->healthPayload($instrument);

        // 首載瘦身：不再輸出 prices/indicators，前端掛載後另打 stocks.chart endpoint
        // 取 5 年日/週/月 K 與完整指標序列，避免首頁 payload 過大。
        return Inertia::render('Stocks/Search', [
            'symbol' => $instrument->symbol,
            'instrument' => $this->instrumentPayload($instrument),
            'quote' => $this->quotePayload($quote),
            'news' => array_map(fn (object $item): array => $this->newsPayload($item), $news),
            'analyses' => $this->analysisPayload($request, $instrument),
            'chatTurns' => $this->chatPayload($request, $instrument),
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
            // 券商分點主力摘要；null 代表非台股/需贊助等級/抓取失敗，前端據此降級。
            'brokerBranch' => $brokerBranch,
            'socialArbitrage' => $this->socialArbitragePayload($arbitrage),
            'industryMomentum' => $this->industryMomentumPayload($momentum),
            'health' => $health,
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

        // AI 分析輸出語言跟隨使用者偏好；未設定則繁中。
        $locale = $user->profile?->locale ?? 'zh';

        RunStockAnalysis::dispatch($analysis->id, $setting?->id, $model, $locale);

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
            // 必須存在：輪詢的 only: ['analyses','chatTurns'] 若落到這個分支而
            // 少了這個 key，前端會拿到 undefined 並在 turns.some() 炸掉整頁。
            'chatTurns' => [],
            'llmProviders' => [],
            'chipFlows' => [],
            'marginFlows' => [],
            'brokerBranch' => null,
            'socialArbitrage' => null,
            'industryMomentum' => null,
            'health' => null,
        ];
    }

    /**
     * 短線／中長線體質判讀的前端 payload。
     *
     * **形狀就是兩個 reader 與快照自己的 `toArray()`，這裡一個欄位都不重組。**
     * 階段 4 的 I4 是同一份 payload 的形狀被抄在兩個 controller 裡，加欄位時只
     * 改到一邊；判讀還多一層風險——送進 prompt 的那份與畫面上這份必須是同一個
     * 形狀，否則使用者看到的與 LLM 讀到的會逐漸漂移。
     *
     * **走 `cachedFor()` 不是 `freshFor()`。** 個股頁是同步 web 請求，那條路徑
     * 沒有分析 job 的 timeout 或掃描的時間預算可用，而 PHP 的
     * `max_execution_time` 不是例外、`try/catch` 攔不到（階段 3 的 C1 就是這個
     * 形狀）。代價是判讀可能不是最新的——所以 `cached_only` 會是 true，前端
     * 必須把那句說明顯示出來。首次被搜尋、快取還是空的標的因此每一塊都是
     * 不可評估，那是誠實的：資料真的還沒有，跑一次個股分析（走 `freshFor()`）
     * 就會落地。
     */
    private function healthPayload(Instrument $instrument): array
    {
        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, self::HEALTH_BARS);

        return [
            'short' => app(ShortTermHealthReader::class)->read($snapshot)->toArray(),
            'long' => app(LongTermHealthReader::class)->read($snapshot)->toArray(),
            'snapshot' => $snapshot->toArray(),
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

    /**
     * 這次請求是不是只為了輪詢分析與問答狀態。
     *
     * 比對「請求的 props 是否完全落在這兩個之內」而不是只看有沒有帶標頭：頁面上
     * 若日後出現其他部分重載，精簡分支不能把它需要的 props 一起吞掉。
     */
    private function isPollOnly(Request $request): bool
    {
        $partial = array_filter(array_map(
            'trim',
            explode(',', (string) $request->header('X-Inertia-Partial-Data')),
        ));

        return $partial !== [] && array_diff($partial, ['analyses', 'chatTurns']) === [];
    }

    private function findBySymbol(string $symbol): ?Instrument
    {
        // 輪詢時不走 marketData->quote()＋createOrFirst：使用者已經在這一檔的頁面
        // 上，標的必然存在，為了拿它去打一次行情 API 不划算。
        return Instrument::query()->where('symbol', $symbol)->first();
    }

    /**
     * 頁面顯示 12 輪，送進 prompt 只有 6 輪——看得到的比記得住的多是刻意的。
     *
     * 以 id 排序而非 created_at：MySQL 的 timestamp 預設沒有微秒精度。
     */
    private function chatPayload(Request $request, Instrument $instrument): array
    {
        return $request->user()
            ->stockChatTurns()
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('id')
            ->limit(self::CHAT_TURNS_ON_PAGE)
            ->get()
            ->reverse()
            ->map(fn (StockChatTurn $turn): array => [
                'id' => $turn->id,
                'question' => $turn->question,
                'answer' => $turn->answer,
                'status' => $turn->status->value,
                'provider_type' => $turn->provider_type,
                'model' => $turn->model,
                'metadata' => $turn->metadata ?? [],
                'created_at' => $turn->created_at?->toIso8601String(),
            ])
            // reverse() 保留原 key，不 values() 會序列化成 JSON object。
            ->values()
            ->all();
    }

    /**
     * 社交套利分類的前端 payload。
     *
     * **分類、各腿判定、細分原因全部由後端算好送出**，前端一律不重算：前端若自己
     * 依門檻再判一次，會出現「畫面顯示的」與「prompt 給 LLM 的」不一致——同一份
     * 資料兩套結論。判定鍵走 {@see SocialArbitrageVerdicts}，與 prompt 區塊
     * （{@see SocialArbitrageGuide}）共用同一份優先序。
     *
     * 門檻一併送出，但**只供顯示**：本功能的門檻全都沒做過預測力回測（見 config
     * 各鍵註解），只給「法人買：是」等於把一條武斷的線包裝成事實，使用者無從判斷
     * 結論離門檻有多遠。
     *
     * 三條數值腿的 `value` 為 `null` 時**照實送 null**，不以 0 代替：`0` 是
     * 「有資料且為零」這個實質宣稱，而美股的法人腿恆為 null。
     */
    private function socialArbitragePayload(SocialArbitrage $arbitrage): array
    {
        $heat = $arbitrage->heat;
        $highWaterVerdict = SocialArbitrageVerdicts::highWater($arbitrage);

        return [
            'stage' => $arbitrage->stage->value,
            'insufficient_reason' => $arbitrage->insufficientReason?->value,
            'window_days' => (int) $this->requireNumeric('order_inventory.social.heat_window_days'),
            'heat' => [
                'recent_count' => $heat->recentCount,
                'prior_count' => $heat->priorCount,
                // 前期 0 則時變化率無定義（除以 0），照實送 null——前端據此略過
                // 那半句，印「變化 0.0%」會把「算不出來」講成「沒有變化」。
                'change_ratio' => $heat->changeRatio,
                // 熱度這一列**刻意沒有 evaluable 欄位**（三條腿有）：則數是事實、
                // 永遠顯示，沒有「不可評估」的渲染分支可切。而「不予判定」已經完整
                // 編碼在 verdict 上——heatUp 只在 hasEnoughSamples 為 false 時是 null
                // （見 SocialArbitrageClassifier），對應 heat_unevaluable。多送一個
                // 同義欄位只會多一份會漂移的真相，前端也從來沒讀過它。
                'verdict' => SocialArbitrageVerdicts::heat($arbitrage),
                'min_recent_mentions' => (int) $this->requireNumeric('order_inventory.social.min_recent_mentions'),
                'rise_ratio' => $this->requireNumeric('order_inventory.social.heat_rise_ratio'),
                // 門檻算不出來時整段缺席：印一個 0.0 則的門檻會讓剛被報導的標的
                // 立刻看起來像高檔。
                'high_water' => $highWaterVerdict === null ? null : [
                    'threshold' => $heat->highWaterThreshold,
                    'verdict' => $highWaterVerdict,
                ],
            ],
            'legs' => [
                'price' => [
                    'evaluable' => $arbitrage->priceLegEvaluable,
                    'verdict' => SocialArbitrageVerdicts::price($arbitrage),
                    'value' => $arbitrage->priceChange,
                    'thresholds' => [
                        'risen' => $this->requireNumeric('order_inventory.social.price_risen'),
                        'surged' => $this->requireNumeric('order_inventory.social.price_surged'),
                        'flat' => $this->requireNumeric('order_inventory.social.price_flat'),
                        'fell' => $this->requireNumeric('order_inventory.social.price_fell'),
                    ],
                ],
                'foreign' => [
                    'evaluable' => $arbitrage->foreignLegEvaluable,
                    'verdict' => SocialArbitrageVerdicts::foreign($arbitrage),
                    // 分母是**同期成交量**不是股本：本專案沒有流通股數來源
                    // （見 config 註解與 commit 1ab7420），文案必須照著寫。
                    'value' => $arbitrage->foreignVolumeShare,
                    'thresholds' => [
                        'buy' => $this->requireNumeric('order_inventory.social.foreign_net_buy_volume_share'),
                        'heavy' => $this->requireNumeric('order_inventory.social.foreign_net_buy_volume_share_heavy'),
                    ],
                ],
                // 營收腿的原始輸入本身就是布林（訂單庫存框架的 C1），沒有數值可印。
                'revenue' => [
                    'evaluable' => $arbitrage->revenueLegEvaluable,
                    'verdict' => SocialArbitrageVerdicts::revenue($arbitrage),
                    'value' => null,
                    'thresholds' => null,
                ],
                'margin' => [
                    'evaluable' => $arbitrage->marginLegEvaluable,
                    'verdict' => SocialArbitrageVerdicts::margin($arbitrage),
                    'value' => $arbitrage->grossMarginQoqPp,
                    'thresholds' => [
                        'stable_band' => $this->requireNumeric('order_inventory.thresholds.gross_margin_stable_pp'),
                    ],
                ],
            ],
        ];
    }

    /**
     * 產業動能的前端 payload。
     *
     * 不適用時**只送 applicable 與 reason，不送任何數字**（與
     * {@see IndustryMomentum::notApplicable()} 同一個理由）：留著半套數字會讓
     * 前端以為可以拿來比較。「不適用」（這個市場沒有這個功能）與「樣本不足」
     * （applicable = true、median 為 null、samples 照實回報）是兩件事，前端據
     * 這兩個欄位分辨，不得自行重判市場或產業。
     */
    private function industryMomentumPayload(IndustryMomentum $momentum): array
    {
        if (! $momentum->applicable) {
            return [
                'applicable' => false,
                'reason' => $momentum->reason?->value,
            ];
        }

        return [
            'applicable' => true,
            'reason' => null,
            'industry' => $momentum->industry,
            'median' => $momentum->median,
            'own' => $momentum->own,
            'excess' => $momentum->excess,
            // 樣本數一律送出（0 也送）：不寫會讓使用者以為系統看過整個產業。
            'samples' => $momentum->samples,
            'min_samples' => (int) $this->requireNumeric('order_inventory.industry_momentum.min_samples'),
            'thresholds' => [
                'industry_accelerating' => $this->requireNumeric('order_inventory.industry_momentum.industry_accelerating'),
                'outperformance' => $this->requireNumeric('order_inventory.industry_momentum.outperformance'),
            ],
        ];
    }

    /**
     * 讀一個門檻，缺鍵或非數值一律拋錯。
     *
     * 與 SocialArbitrageGuide::threshold() 同一個理由：裸 `(float)` 轉型會讓
     * 「已漲門檻 +0.0%」印在畫面上，看起來像判定寫錯，而實際是讀不到設定。
     * 這些鍵全部寫在版控裡的 config，缺鍵是部署問題，讓它拋出來。
     */
    private function requireNumeric(string $path): float
    {
        $value = config($path);

        if (! is_numeric($value)) {
            throw new \RuntimeException("$path config 缺失或非數值，無法輸出社交套利／產業動能面板的門檻。");
        }

        return (float) $value;
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
                // 生成當下的判讀。**必須逐筆帶出來**：同一個頁面另外渲染一份
                // 「現在」算出來的判讀面板，不帶的話歷史分析的文字會一直引用一份
                // 畫面上看不到的舊結論，而使用者無從得知哪一個算數。
                // 舊列為 null（migration 之前沒有這個功能），呈現層據此整段不顯示。
                'health_read' => $analysis->health_read,
                'llm_output' => $analysis->llm_output,
                'data_as_of' => $analysis->data_as_of?->toIso8601String(),
                'created_at' => $analysis->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
