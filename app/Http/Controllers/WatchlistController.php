<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Support\MarketResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WatchlistController extends Controller
{
    public function index(Request $request): Response
    {
        $watchlists = $request->user()
            ->watchlists()
            ->with(['items.instrument'])
            ->orderBy('name')
            ->get()
            ->map(fn (Watchlist $watchlist): array => [
                'id' => $watchlist->id,
                'name' => $watchlist->name,
                'items' => $watchlist->items->map(fn (WatchlistItem $item): array => [
                    'id' => $item->id,
                    'note' => $item->note,
                    'sort_order' => $item->sort_order,
                    'instrument' => [
                        'id' => $item->instrument->id,
                        'symbol' => $item->instrument->symbol,
                        'name' => $item->instrument->name,
                        'market' => $item->instrument->market->value,
                        'asset_type' => $item->instrument->asset_type->value,
                        'currency' => $item->instrument->currency,
                        'exchange' => $item->instrument->exchange,
                    ],
                ])->values(),
            ])->values();

        return Inertia::render('Watchlists/Index', [
            'watchlists' => $watchlists,
            // 已在投資組合中的標的。UI 需要據此標示狀態——否則使用者要填完
            // 股數與成本、送出後才會收到「該標的已在投資組合中」的錯誤。
            'heldInstrumentIds' => $request->user()->holdings()->pluck('instrument_id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeWatchlistName($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('watchlists', 'name')->where('user_id', $request->user()->id),
            ],
        ]);

        try {
            $request->user()->watchlists()->create($data);
        } catch (QueryException $exception) {
            $this->throwUniqueValidation($exception, 'name', 'A watchlist with this name already exists.');
        }

        return redirect()->route('watchlists.index');
    }

    public function update(Request $request, Watchlist $watchlist): RedirectResponse
    {
        $this->authorizeWatchlist($request, $watchlist);
        $this->normalizeWatchlistName($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('watchlists', 'name')
                    ->where('user_id', $request->user()->id)
                    ->ignore($watchlist->id),
            ],
        ]);

        try {
            $watchlist->update($data);
        } catch (QueryException $exception) {
            $this->throwUniqueValidation($exception, 'name', 'A watchlist with this name already exists.');
        }

        return redirect()->route('watchlists.index');
    }

    public function destroy(Request $request, Watchlist $watchlist): RedirectResponse
    {
        $this->authorizeWatchlist($request, $watchlist);

        $watchlist->delete();

        return redirect()->route('watchlists.index');
    }

    public function addItem(Request $request, Watchlist $watchlist): RedirectResponse
    {
        $this->authorizeWatchlist($request, $watchlist);
        $this->normalizeInstrumentInput($request);

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9.\-]+$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'market' => ['nullable', 'in:TW,US'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // market 由請求帶入時以請求為準（使用者可能加的是同代號的另一市場掛牌），
        // 沒帶才推導；currency 一律跟著最終的 market 走，不另外推。
        $market = $data['market'] ?? MarketResolver::region($data['symbol'])->value;

        $instrument = Instrument::query()->firstOrCreate(
            ['symbol' => $data['symbol']],
            [
                'name' => $data['name'] ?? $data['symbol'],
                'market' => $market,
                // 原本硬寫 'stock'。台股 ETF（`00` 開頭）規則判得出來，標成個股會讓
                // LongTermHealthReader 照個股走完四塊判定，roe 為 null 落到「資料還沒
                // 累積到可判定的量，再跑幾次就會有」——ETF 永遠不會有 ROE，那句話是假的。
                // 美股仍受限於 MarketResolver::isEtf() 那份不完整的清單，見其 docblock。
                'asset_type' => MarketResolver::assetType($data['symbol']),
                'currency' => $market === 'TW' ? 'TWD' : 'USD',
                'exchange' => null,
            ],
        );

        // Backfill the real company name when the instrument was previously
        // auto-created with name == symbol (e.g. by the price cache).
        if (! empty($data['name']) && ($instrument->name === '' || $instrument->name === $instrument->symbol)) {
            $instrument->update(['name' => $data['name'], 'market' => $market]);
        }

        if ($watchlist->items()->where('instrument_id', $instrument->id)->exists()) {
            throw ValidationException::withMessages([
                'symbol' => 'This symbol is already on this watchlist.',
            ]);
        }

        try {
            $watchlist->items()->create([
                'instrument_id' => $instrument->id,
                'sort_order' => ((int) $watchlist->items()->max('sort_order')) + 1,
                'note' => $data['note'] ?? null,
            ]);
        } catch (QueryException $exception) {
            $this->throwUniqueValidation($exception, 'symbol', 'This symbol is already on this watchlist.');
        }

        // 成功後 redirect back，而非固定導回 watchlists.index：
        // 自選頁呼叫時 back 即該頁（行為等價），Screener 等頁內呼叫時可留在原結果頁。
        return redirect()->back();
    }

    public function removeItem(Request $request, Watchlist $watchlist, WatchlistItem $watchlistItem): RedirectResponse
    {
        $this->authorizeWatchlist($request, $watchlist);

        abort_unless($watchlistItem->watchlist_id === $watchlist->id, 404);

        $watchlistItem->delete();

        return redirect()->route('watchlists.index');
    }

    private function authorizeWatchlist(Request $request, Watchlist $watchlist): void
    {
        abort_unless($watchlist->user_id === $request->user()->id, 403);
    }

    private function normalizeWatchlistName(Request $request): void
    {
        $request->merge([
            'name' => trim((string) $request->input('name', '')),
        ]);
    }

    private function normalizeInstrumentInput(Request $request): void
    {
        $name = $request->input('name');
        $market = $request->input('market');

        $request->merge([
            'symbol' => strtoupper(trim((string) $request->input('symbol', ''))),
            'name' => $name === null ? null : trim((string) $name),
            'market' => $market === null ? null : strtoupper(trim((string) $market)),
            'note' => $request->input('note') === null ? null : trim((string) $request->input('note')),
        ]);
    }

    private function throwUniqueValidation(QueryException $exception, string $field, string $message): never
    {
        if (! $this->isUniqueConstraintViolation($exception)) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            $field => $message,
        ]);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = $exception->getMessage();

        return $sqlState === '23505'
            || $driverCode === '1062'
            || str_contains($message, 'UNIQUE constraint failed');
    }
}
