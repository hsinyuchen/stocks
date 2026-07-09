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
            'signalRules' => collect($registry->all())
                ->map(fn ($rule) => ['key' => $rule->key(), 'label' => $rule->label()])
                ->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['symbol' => strtoupper(trim((string) $request->input('symbol', '')))]);
        $type = (string) $request->input('type');
        $isPrice = in_array($type, self::PRICE_TYPES, true);

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:'.self::SYMBOL_REGEX],
            'type' => ['required', Rule::in(self::TYPES)],
            // 互斥：非訊號類必填 threshold 且訊號類禁帶；價格類禁負。
            'threshold' => [
                'exclude_if:type,signal',
                'required',
                'numeric',
                'max:9999999999999999',
                ...($isPrice ? ['gt:0'] : []),
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
        return [
            'id' => $alert->id,
            'symbol' => $alert->instrument->symbol,
            'name' => $alert->instrument->name,
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
