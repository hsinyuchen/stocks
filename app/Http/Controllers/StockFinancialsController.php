<?php

namespace App\Http\Controllers;

use App\Enums\PeriodType;
use App\Http\Middleware\ProcessQueuedAnalyses;
use App\Models\Instrument;
use App\Services\FinancialStatements\FinancialStatementDispatcher;
use App\Services\FinancialStatements\FinancialStatementsPayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 財報三表子頁面。
 *
 * 派工的門檻是「已登入 ＋ 非 partial 請求」：
 * - 未登入不派工——爬蟲與預覽卡片也會打這條路由，讓它們觸發外部請求等於把
 *   FinMind 額度與 SEC 限速交給任何人消耗。
 * - partial 請求不派工——那是輪詢。每 3 秒派一次工會讓 generation 一直遞增，
 *   job 永遠在追自己的尾巴。判準與 {@see ProcessQueuedAnalyses::shouldSkip()}
 *   同一種（`X-Inertia-Partial-Data` 標頭），不另外發明一套。
 *
 * Reader 本身刻意只讀不派工（見它的 docblock），派工是這裡的責任。
 */
class StockFinancialsController extends Controller
{
    public function __construct(
        private readonly FinancialStatementsPayload $payload,
        private readonly FinancialStatementDispatcher $dispatcher,
    ) {}

    public function __invoke(Request $request, Instrument $instrument): Response
    {
        $type = $this->periodType($request);

        if ($this->shouldDispatch($request)) {
            $this->dispatcher->dispatchFor($instrument);
        }

        // payload 先落區域變數再放進 Inertia::render() 的陣列：本專案實測過
        // 「陣列字面值裡多一個方法呼叫會 segfault」，既有 controller 都這樣寫
        // （見 StockSearchController.php:129-137 的說明）。
        $financials = $this->payload->build($instrument, $type, $request->boolean('expanded'));

        return Inertia::render('Stocks/Financials', [
            // route-model binding 綁的是 id（Instrument 沒有自訂 getRouteKeyName()），
            // 前端換頁／輪詢要重建網址時無法從 symbol 反推，所以額外帶一份 id。
            'instrumentId' => $instrument->id,
            'symbol' => $instrument->symbol,
            'instrumentName' => $instrument->name,
            'financials' => $financials,
        ]);
    }

    /** query 參數來自使用者，不可信——非法值一律退回季度而不是拋例外。 */
    private function periodType(Request $request): PeriodType
    {
        return $request->query('type') === 'annual'
            ? PeriodType::Annual
            : PeriodType::Quarter;
    }

    private function shouldDispatch(Request $request): bool
    {
        return $request->user() !== null && ! $request->hasHeader('X-Inertia-Partial-Data');
    }
}
