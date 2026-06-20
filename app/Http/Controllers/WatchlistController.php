<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Enums\MarketRegion;
use App\Models\Instrument;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
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
            'marketOptions' => collect(MarketRegion::cases())
                ->map(fn (MarketRegion $market): array => [
                    'value' => $market->value,
                    'label' => $market->name,
                ])
                ->values(),
            'assetTypeOptions' => collect(AssetType::cases())
                ->map(fn (AssetType $assetType): array => [
                    'value' => $assetType->value,
                    'label' => $assetType->name,
                ])
                ->values(),
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

        $request->user()->watchlists()->create($data);

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

        $watchlist->update($data);

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
            'symbol' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'market' => ['required', Rule::enum(MarketRegion::class)],
            'asset_type' => ['required', Rule::enum(AssetType::class)],
            'currency' => ['required', 'string', 'max:8'],
            'exchange' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $instrument = Instrument::query()->where('symbol', $data['symbol'])->first();

        if ($instrument && $watchlist->items()->where('instrument_id', $instrument->id)->exists()) {
            throw ValidationException::withMessages([
                'symbol' => 'This symbol is already on this watchlist.',
            ]);
        }

        $instrument ??= Instrument::query()->firstOrCreate(
            ['symbol' => $data['symbol']],
            [
                'name' => $data['name'],
                'market' => $data['market'],
                'asset_type' => $data['asset_type'],
                'currency' => $data['currency'],
                'exchange' => $data['exchange'] ?? null,
            ],
        );

        $watchlist->items()->create([
            'instrument_id' => $instrument->id,
            'sort_order' => ((int) $watchlist->items()->max('sort_order')) + 1,
            'note' => $data['note'] ?? null,
        ]);

        return redirect()->route('watchlists.index');
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
        $exchange = $request->input('exchange');

        $request->merge([
            'symbol' => strtoupper(trim((string) $request->input('symbol', ''))),
            'name' => trim((string) $request->input('name', '')),
            'currency' => strtoupper(trim((string) $request->input('currency', ''))),
            'exchange' => $exchange === null ? null : trim((string) $exchange),
            'note' => $request->input('note') === null ? null : trim((string) $request->input('note')),
        ]);
    }
}
