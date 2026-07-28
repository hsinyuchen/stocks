<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Services\Instruments\InstrumentImportService;
use App\Support\MarketResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * 標的清單維護。
 *
 * instruments 是全站共用資料（行情、籌碼、基本面快取都掛在它底下），一人修改
 * 會影響所有使用者，因此限 admin。個別使用者的自選股仍走 /watchlists。
 */
class InstrumentController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'market' => ['nullable', 'string', 'in:TW,US'],
        ]);

        $query = Instrument::query()
            ->withCount(['watchlistItems', 'stockAnalyses', 'holdings', 'alerts'])
            ->orderBy('symbol');

        if (($filters['q'] ?? '') !== '') {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($inner) => $inner->where('symbol', 'like', $term)->orWhere('name', 'like', $term));
        }

        if (($filters['market'] ?? '') !== '') {
            $query->where('market', $filters['market']);
        }

        $instruments = $query->paginate(self::PER_PAGE)->withQueryString();

        $instruments->through(fn (Instrument $instrument): array => [
            'id' => $instrument->id,
            'symbol' => $instrument->symbol,
            'name' => $instrument->name,
            'market' => $instrument->market->value,
            'asset_type' => $instrument->asset_type->value,
            'currency' => $instrument->currency,
            // 被使用者資料參照者在「全部取代」時會被保留，UI 需標示出來。
            'referenced' => ($instrument->watchlist_items_count
                + $instrument->stock_analyses_count
                + $instrument->holdings_count
                + $instrument->alerts_count) > 0,
        ]);

        return Inertia::render('Admin/Instruments', [
            'instruments' => $instruments,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'market' => $filters['market'] ?? null,
            ],
            'total' => Instrument::query()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9.\-^]+$/'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $symbol = strtoupper(trim($data['symbol']));

        if (Instrument::query()->where('symbol', $symbol)->exists()) {
            return back()->withErrors(['symbol' => "代號 {$symbol} 已存在。"]);
        }

        Instrument::query()->create([
            'symbol' => $symbol,
            'name' => trim((string) ($data['name'] ?? '')) ?: $symbol,
            'market' => MarketResolver::region($symbol),
            'asset_type' => MarketResolver::assetType($symbol),
            'currency' => MarketResolver::currency($symbol),
            'exchange' => null,
        ]);

        return back()->with('status', "已新增 {$symbol}。");
    }

    /** 只允許改名稱：代號變動等同換一檔股票，會讓既有快取與使用者資料對不上。 */
    public function update(Request $request, Instrument $instrument): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $instrument->update(['name' => trim($data['name'])]);

        return back()->with('status', "已更新 {$instrument->symbol}。");
    }

    public function destroy(Instrument $instrument): RedirectResponse
    {
        if ($instrument->isReferencedByUserData()) {
            return back()->withErrors([
                'instrument' => "{$instrument->symbol} 已被自選清單、持倉、警報或已存分析參照，無法刪除。",
            ]);
        }

        $symbol = $instrument->symbol;
        $instrument->delete();

        return back()->with('status', "已刪除 {$symbol}。");
    }

    public function import(Request $request, InstrumentImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx'],
            'mode' => ['required', 'in:append,replace'],
        ]);

        try {
            $parsed = $service->parse($data['file']);
        } catch (Throwable $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        if ($parsed['rows'] === []) {
            return back()->withErrors(['file' => '檔案中沒有可匯入的資料列。']);
        }

        $result = $service->import($parsed['rows'], $data['mode']);

        $message = sprintf(
            '匯入完成：新增 %d 筆、跳過重複 %d 筆',
            $result['created'],
            $result['skipped'],
        );

        if ($data['mode'] === 'replace') {
            $message .= sprintf('、移除 %d 筆、保留被使用者資料參照的 %d 筆', $result['removed'], $result['protected']);
        }

        if ($parsed['errors'] !== []) {
            $message .= sprintf('。另有 %d 列格式無效已略過', count($parsed['errors']));
        }

        return back()->with('status', $message.'。');
    }
}
