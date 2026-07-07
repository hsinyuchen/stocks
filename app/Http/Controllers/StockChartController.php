<?php

namespace App\Http\Controllers;

use App\Contracts\MarketDataProvider;
use App\Models\Instrument;
use App\Services\PriceAggregationService;
use App\Services\TechnicalIndicatorService;
use App\Support\MarketResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockChartController extends Controller
{
    /** 5 年交易日概量；provider 端 coverage/range 已支援（Task 1/2）。 */
    private const HISTORY_DAYS = 1300;

    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly PriceAggregationService $aggregation,
        private readonly TechnicalIndicatorService $indicators,
    ) {}

    public function __invoke(Request $request, Instrument $instrument): JsonResponse
    {
        $tf = $this->validateTimeframe($request);

        return response()->json($this->buildPayload($instrument->symbol, $tf));
    }

    /**
     * by-symbol 版本：比較模式取用。比較 symbol 可能無現存 instrument
     * （含 ^TWII 等指數），故以 symbol 查詢，內部 firstOrCreate instrument。
     * regex 放寬含前導 ^，允許指數 symbol；其餘與主端點共用回傳格式。
     */
    public function bySymbol(Request $request): JsonResponse
    {
        // 前端可能傳小寫；先正規化再驗證，與 StockSearchController::normalizeSymbolInput 一致。
        $request->merge([
            'symbol' => strtoupper(trim((string) $request->query('symbol', ''))),
        ]);

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9.\-^]+$/'],
            'tf' => ['nullable', 'in:daily,weekly,monthly'],
        ]);

        $symbol = $data['symbol'];
        $tf = $data['tf'] ?? 'daily';

        $this->resolveInstrument($symbol);

        return response()->json($this->buildPayload($symbol, $tf));
    }

    /** tf 白名單驗證；缺省 daily。 */
    private function validateTimeframe(Request $request): string
    {
        $data = $request->validate([
            'tf' => ['nullable', 'in:daily,weekly,monthly'],
        ]);

        return $data['tf'] ?? 'daily';
    }

    /**
     * 供 by-symbol 端點：確保 instrument 存在（供後續分析/自選複用），
     * 市場/幣別由 symbol 推導，與 StockSearchController 同一 MarketResolver。
     */
    private function resolveInstrument(string $symbol): Instrument
    {
        return Instrument::query()->createOrFirst(
            ['symbol' => $symbol],
            [
                'name' => $symbol,
                'market' => MarketResolver::region($symbol),
                'asset_type' => MarketResolver::assetType($symbol),
                'currency' => MarketResolver::currency($symbol),
                'exchange' => null,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $symbol, string $tf): array
    {
        $prices = $this->marketData->dailyPrices($symbol, self::HISTORY_DAYS);

        $prices = match ($tf) {
            'weekly' => $this->aggregation->toWeekly($prices),
            'monthly' => $this->aggregation->toMonthly($prices),
            default => $prices,
        };

        if ($prices === []) {
            return [
                'symbol' => $symbol,
                'timeframe' => $tf,
                'candles' => [],
                'indicators' => (object) [],
            ];
        }

        $series = $this->indicators->series($prices);

        $candles = [];
        foreach ($prices as $price) {
            $candles[] = [
                'time' => $price->date,
                'open' => $price->open,
                'high' => $price->high,
                'low' => $price->low,
                'close' => $price->close,
                'volume' => $price->volume,
            ];
        }

        // 月線 bar 數少，MA60 幾乎無值：明確全 null，前端一律隱藏（spec 決策）。
        $ma60 = $tf === 'monthly'
            ? array_fill(0, count($candles), null)
            : $series['ma60'];

        return [
            'symbol' => $symbol,
            'timeframe' => $tf,
            'candles' => $candles,
            'indicators' => [
                'ma5' => $series['ma5'],
                'ma20' => $series['ma20'],
                'ma60' => $ma60,
                'boll_upper' => $series['boll_upper'],
                'boll_middle' => $series['boll_middle'],
                'boll_lower' => $series['boll_lower'],
                'k' => $series['k'],
                'd' => $series['d'],
                'macd' => $series['macd'],
                'macd_signal' => $series['signal'],
                'macd_histogram' => $series['histogram'],
                'rsi' => $series['rsi'],
                'obv' => $series['obv'],
            ],
        ];
    }
}
