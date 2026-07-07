<?php

namespace App\Http\Controllers;

use App\Services\Screener\ScreenerService;
use App\Services\Screener\ScreenRuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ScreenerController extends Controller
{
    public function index(Request $request, ScreenRuleRegistry $registry): Response
    {
        return Inertia::render('Screener/Index', [
            'rules' => collect($registry->all())
                ->map(fn ($rule) => ['key' => $rule->key(), 'label' => $rule->label()])
                ->values()
                ->all(),
            'watchlists' => $request->user()->watchlists()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($watchlist) => ['id' => $watchlist->id, 'name' => $watchlist->name])
                ->all(),
            'universeCount' => count((array) config('screener.universe', [])),
        ]);
    }

    public function scan(Request $request, ScreenerService $screener, ScreenRuleRegistry $registry): JsonResponse
    {
        // rules 白名單以 registry 為單一真相源；未知 key 直接 422，服務層不再重複校驗。
        $data = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'rules.*' => ['string', Rule::in($registry->keys())],
        ]);

        return response()->json($screener->scan($request->user(), $data['rules']));
    }
}
