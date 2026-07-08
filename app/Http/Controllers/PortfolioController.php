<?php

namespace App\Http\Controllers;

use App\Models\Holding;
use App\Models\Instrument;
use App\Services\PortfolioService;
use App\Support\MarketResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    // 投資組合不接受指數（無法持有 ^TWII），故不含 `^`。
    private const SYMBOL_REGEX = '/^[A-Z0-9.\-]+$/';

    // decimal(20,4)：整數部最多 16 位。設上界同時擋掉 `1e400` 溢出成 INF 寫進 DB
    // （INF 通過 numeric + min，讀取時 decimal cast 會拋 MathException，導致 index 永久 500）。
    private const MAX_AMOUNT = '9999999999999999';

    public function index(Request $request, PortfolioService $portfolio): Response
    {
        return Inertia::render('Portfolio/Index', $portfolio->summary($request->user()));
    }

    public function store(Request $request): RedirectResponse
    {
        // 後端正規化：API 直送 nvda 必須等價於 NVDA，不可只靠前端。
        $request->merge(['symbol' => strtoupper(trim((string) $request->input('symbol', '')))]);

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:'.self::SYMBOL_REGEX],
            'shares' => ['required', 'numeric', 'min:0.0001', 'max:'.self::MAX_AMOUNT],
            'avg_cost' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $instrument = $this->resolveInstrument($data['symbol']);

        if ($request->user()->holdings()->where('instrument_id', $instrument->id)->exists()) {
            throw ValidationException::withMessages(['symbol' => '該標的已在投資組合中，請直接編輯。']);
        }

        $holding = new Holding([
            'instrument_id' => $instrument->id,
            'shares' => $data['shares'],
            'avg_cost' => $data['avg_cost'],
            'note' => $data['note'] ?? null,
        ]);
        // currency 非 fillable：伺服端判定後顯式賦值，save() 補 user_id。
        $holding->currency = MarketResolver::currency($instrument->symbol);
        $request->user()->holdings()->save($holding);

        return redirect()->back();
    }

    public function update(Request $request, Holding $holding): RedirectResponse
    {
        $this->authorizeHolding($request, $holding);

        // symbol 不可變更：改標的等於換一筆持倉，請刪除後重加。
        $data = $request->validate([
            'shares' => ['required', 'numeric', 'min:0.0001', 'max:'.self::MAX_AMOUNT],
            'avg_cost' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $holding->update($data);

        return redirect()->back();
    }

    public function destroy(Request $request, Holding $holding): RedirectResponse
    {
        $this->authorizeHolding($request, $holding);

        $holding->delete();

        return redirect()->back();
    }

    private function authorizeHolding(Request $request, Holding $holding): void
    {
        abort_unless($holding->user_id === $request->user()->id, 403);
    }

    /** market / currency / asset_type 一律由 MarketResolver 推導（同 StockChartController）。 */
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
}
