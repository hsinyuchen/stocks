<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Models\ScreenRun;
use App\Services\Screener\ScreenerService;
use App\Services\Screener\ScreenRuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ScreenerController extends Controller
{
    /** 歷史掃描列出的筆數。留存是為了回測，但 UI 只需要最近幾次。 */
    private const HISTORY_LIMIT = 20;

    public function index(Request $request, ScreenRuleRegistry $registry, ScreenerService $screener): Response
    {
        $pool = $screener->poolBreakdown($request->user());

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
            // 全站標的清單（排除指數）的檔數。不再用 config 的 universe——那份
            // 現在只是初始種子，與實際掃描範圍無關。
            'instrumentCount' => $screener->baseInstrumentCount(),
            // 實際掃描檔數＝內建股池 ∪ 自選股（去重），與掃描結果的「掃描 N 支」
            // 一致。只顯示 config 筆數會讓兩個數字對不上。
            'poolCount' => count($pool),
            // 自選股檔數也要給：少了它，畫面只能寫「100 支 ∪ 你的自選股 = 105」，
            // 使用者無從得知少掉的 6 支是重疊還是漏算，看起來就像數字錯了。
            'watchlistCount' => $screener->watchlistSymbolCount($request->user()),
            // 完整明細（約百餘筆 symbol + name），讓使用者能確認自己關心的標的
            // 有沒有被涵蓋，而不是只看到一個黑箱數字。
            'pool' => $pool,
        ]);
    }

    public function scan(Request $request, ScreenerService $screener, ScreenRuleRegistry $registry): JsonResponse
    {
        // rules 白名單以 registry 為單一真相源；未知 key 直接 422，服務層不再重複校驗。
        $data = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'rules.*' => ['string', Rule::in($registry->keys())],
            'exclude' => ['nullable', 'array'],
            'exclude.*' => ['string', Rule::in($registry->keys())],
        ]);

        $result = $screener->scan($request->user(), $data['rules'], $data['exclude'] ?? []);

        // 留存快照。這是回測的第一步：累積之後才能回答「這組規則上週選出哪些、
        // 後來漲跌如何」。失敗不得影響掃描結果回傳——留存是附加價值，不是主流程。
        try {
            $run = $request->user()->screenRuns()->create([
                'rules' => $data['rules'],
                'excludes' => $data['exclude'] ?? [],
                'results' => $result['results'],
                'scanned' => $result['scanned'],
                'matched' => count($result['results']),
                'skipped' => count($result['skipped']),
                'failed' => count($result['failures']),
            ]);

            $result['run_id'] = $run->id;
        } catch (Throwable $exception) {
            Log::warning('screener: failed to persist run', ['error' => $exception->getMessage()]);
        }

        return response()->json($result);
    }

    /** 歷史掃描紀錄。只列摘要，明細在展開時才需要。 */
    public function history(Request $request): JsonResponse
    {
        $runs = $request->user()->screenRuns()
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (ScreenRun $run): array => [
                'id' => $run->id,
                'rules' => $run->rules,
                'excludes' => $run->excludes,
                'scanned' => $run->scanned,
                'matched' => $run->matched,
                'skipped' => $run->skipped,
                'failed' => $run->failed,
                'symbols' => array_column((array) $run->results, 'symbol'),
                'created_at' => $run->created_at?->toIso8601String(),
            ]);

        return response()->json(['runs' => $runs]);
    }

    /**
     * 把掃描結果中選定的股票加入自選清單。
     *
     * 只接受本人的 watchlist 與本人這次掃描實際命中的代號——不能讓前端傳任意
     * symbol 進來，否則這個端點會變成「繞過個股搜尋直接建 instrument」的入口。
     */
    public function addToWatchlist(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'run_id' => ['required', 'integer'],
            'watchlist_id' => ['required', 'integer'],
            'symbols' => ['required', 'array', 'min:1'],
            'symbols.*' => ['string', 'max:32'],
        ]);

        $user = $request->user();

        $run = $user->screenRuns()->whereKey($data['run_id'])->first();
        abort_if($run === null, 403);

        $watchlist = $user->watchlists()->whereKey($data['watchlist_id'])->first();
        abort_if($watchlist === null, 403);

        // 白名單：只能加入這次掃描確實命中的代號。
        $allowed = array_flip(array_column((array) $run->results, 'symbol'));
        $existing = $watchlist->items()->pluck('instrument_id')->flip();

        $added = 0;
        $skipped = 0;

        foreach ($data['symbols'] as $symbol) {
            $symbol = strtoupper(trim($symbol));

            if (! isset($allowed[$symbol])) {
                continue;
            }

            $instrument = Instrument::query()->where('symbol', $symbol)->first();

            if ($instrument === null) {
                continue;
            }

            // 已在清單中就跳過，不重複加入也不覆寫既有備註。
            if ($existing->has($instrument->id)) {
                $skipped++;

                continue;
            }

            $watchlist->items()->create([
                'instrument_id' => $instrument->id,
                'sort_order' => $watchlist->items()->count(),
            ]);

            $existing->put($instrument->id, true);
            $added++;
        }

        return back()->with('status', sprintf(
            '已加入 %d 檔至「%s」%s。',
            $added,
            $watchlist->name,
            $skipped > 0 ? "，略過已存在的 {$skipped} 檔" : '',
        ));
    }
}
