<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Instrument;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Screener\ScreenRuleRegistry;
use App\Support\MarketResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    // 警報不接受指數，故不含 `^`（同 portfolio）。
    private const SYMBOL_REGEX = '/^[A-Z0-9.\-]+$/';

    private const PRICE_TYPES = ['price_above', 'price_below'];

    private const TYPES = ['price_above', 'price_below', 'change_pct_above', 'change_pct_below', 'signal'];

    // 大盤層級警報：無個股標的（instrument_id null），全站條件相同。
    private const MARKET_TYPES = ['market_futures_flip', 'market_bearish_flip'];

    public function index(Request $request, AlertEvaluator $evaluator, ScreenRuleRegistry $registry): Response
    {
        // 開頁時被動檢查（best-effort：evaluator 內已容錯，此為雙層保險）。
        try {
            $evaluator->evaluate($request->user());
        } catch (\Throwable $exception) {
            report($exception);
        }

        $alerts = $request->user()->alerts()->with('instrument')->latest()->get()
            ->map(fn (Alert $alert): array => $this->payload($alert));

        return Inertia::render('Alerts/Index', [
            'active' => $alerts->where('status', 'active')->values()->all(),
            'triggered' => $alerts->where('status', 'triggered')->values()->all(),
            'signalRules' => $registry->listing(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = (string) $request->input('type');

        if (in_array($type, self::MARKET_TYPES, true)) {
            return $this->storeMarketAlert($request, $type);
        }

        $request->merge(['symbol' => strtoupper(trim((string) $request->input('symbol', '')))]);
        $isPrice = in_array($type, self::PRICE_TYPES, true);

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:'.self::SYMBOL_REGEX],
            'type' => ['required', Rule::in(self::TYPES)],
            // 互斥：非訊號類必填 threshold 且訊號類禁帶；價格類禁負，
            // change_pct 類補下界（擋 "-1e999" → -INF 溢出 decimal 欄位）。
            'threshold' => [
                'exclude_if:type,signal',
                'required',
                'numeric',
                'max:9999999999999999',
                ...($isPrice ? ['gt:0'] : ['min:-9999999999999999']),
            ],
            'signal_key' => [
                Rule::requiredIf($type === 'signal'),
                Rule::prohibitedIf($type !== 'signal'),
                Rule::in(app(ScreenRuleRegistry::class)->keys()),
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // 訊號類誤帶 threshold：明確拒絕（exclude_if 會移除它，但要擋「填了卻不該填」）
        if ($type === 'signal' && $request->filled('threshold')) {
            return back()->withErrors(['threshold' => '技術訊號警報不需填門檻。'])->withInput();
        }

        $instrument = $this->resolveInstrument($data['symbol']);

        $alert = new Alert([
            'instrument_id' => $instrument->id,
            'type' => $data['type'],
            'threshold' => $data['threshold'] ?? null,
            'signal_key' => $data['signal_key'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
        $request->user()->alerts()->save($alert);   // save() 補 user_id

        return redirect()->back();
    }

    /**
     * 大盤層級警報：無 symbol / threshold / signal_key，instrument_id 存 null。
     * 每人同類型只留一筆監控中——條件全站相同，多筆只會同時觸發洗版。
     */
    private function storeMarketAlert(Request $request, string $type): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::MARKET_TYPES)],
            'symbol' => ['prohibited'],
            'threshold' => ['prohibited'],
            'signal_key' => ['prohibited'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $exists = $request->user()->alerts()
            ->where('type', $type)
            ->where('status', 'active')
            ->exists();

        if ($exists) {
            return back()->withErrors(['type' => '已有一筆監控中的大盤警報，請勿重複新增。'])->withInput();
        }

        $alert = new Alert(['type' => $type, 'note' => $data['note'] ?? null]);
        $request->user()->alerts()->save($alert);

        return redirect()->back();
    }

    public function reactivate(Request $request, Alert $alert): RedirectResponse
    {
        $this->authorizeAlert($request, $alert);

        $alert->forceFill(['status' => 'active', 'triggered_at' => null, 'triggered_price' => null])->save();

        return redirect()->back();
    }

    public function destroy(Request $request, Alert $alert): RedirectResponse
    {
        $this->authorizeAlert($request, $alert);

        $alert->delete();

        return redirect()->back();
    }

    private function authorizeAlert(Request $request, Alert $alert): void
    {
        abort_unless($alert->user_id === $request->user()->id, 403);
    }

    /** @return array<string, mixed> */
    private function payload(Alert $alert): array
    {
        // 大盤層級警報無個股標的，instrument 為 null。
        $isMarket = in_array($alert->type, self::MARKET_TYPES, true);

        return [
            'id' => $alert->id,
            'scope' => $isMarket ? 'market' : 'instrument',
            'symbol' => $alert->instrument?->symbol,
            'name' => $alert->instrument?->name,
            'type' => $alert->type,
            'threshold' => $alert->threshold === null ? null : (float) $alert->threshold,
            'signal_key' => $alert->signal_key,
            'status' => $alert->status,
            'triggered_at' => $alert->triggered_at?->toIso8601String(),
            'triggered_price' => $alert->triggered_price === null ? null : (float) $alert->triggered_price,
            'note' => $alert->note,
        ];
    }

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
