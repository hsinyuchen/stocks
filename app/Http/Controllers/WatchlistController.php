<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
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
            'symbol' => ['required', 'string', 'max:32', Rule::exists('instruments', 'symbol')],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'symbol.exists' => 'Search and create this instrument before adding it to a watchlist.',
        ]);

        $instrument = Instrument::query()
            ->where('symbol', $data['symbol'])
            ->firstOrFail();

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
        $request->merge([
            'symbol' => strtoupper(trim((string) $request->input('symbol', ''))),
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
