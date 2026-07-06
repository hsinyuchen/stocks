<?php

namespace App\Http\Controllers;

use App\Contracts\MarketDataProvider;
use App\Models\Instrument;
use App\Services\PriceAggregationService;
use App\Services\TechnicalIndicatorService;
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
        $data = $request->validate([
            'tf' => ['nullable', 'in:daily,weekly,monthly'],
        ]);
        $tf = $data['tf'] ?? 'daily';

        $prices = $this->marketData->dailyPrices($instrument->symbol, self::HISTORY_DAYS);

        $prices = match ($tf) {
            'weekly' => $this->aggregation->toWeekly($prices),
            'monthly' => $this->aggregation->toMonthly($prices),
            default => $prices,
        };

        if ($prices === []) {
            return response()->json([
                'symbol' => $instrument->symbol,
                'timeframe' => $tf,
                'candles' => [],
                'indicators' => (object) [],
            ]);
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

        return response()->json([
            'symbol' => $instrument->symbol,
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
        ]);
    }
}
