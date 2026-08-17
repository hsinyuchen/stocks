<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Jobs\RunStockChatReply;
use App\Models\Instrument;
use App\Models\LlmProviderSetting;
use App\Models\StockChatTurn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 個股頁的 AI 投資顧問問答。
 *
 * 獨立於 StockSearchController：後者已經同時負責搜尋、報價與分析三塊，再塞進
 * 問答的三個 action 會讓它難以閱讀。頁面顯示用的 chatPayload 仍留在那邊，因為
 * 它是 index() 渲染的一部分。
 */
class StockChatController extends Controller
{
    /** 提問長度上限。夠寫一段完整問題，同時把未受信任輸入鎖在可預期的範圍。 */
    private const MAX_QUESTION = 500;

    public function store(Request $request, Instrument $instrument): RedirectResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:'.self::MAX_QUESTION],
            'model' => ['nullable', 'string', 'max:120'],
            'llm_provider_setting_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $locale = $user->profile?->locale ?? 'zh';
        $setting = $this->resolveSetting($user, $data['llm_provider_setting_id'] ?? null);

        // 沒有模型就完全不能用：與個股分析不同，這裡沒有規則訊號可以降級，落一筆
        // pending 只會變成一筆註定失敗的紀錄。
        //
        // 用 withErrors 而非 flash：Stocks/Search.jsx 只渲染 form.errors，沒有
        // flash 區塊，用 with('error') 訊息會直接消失在畫面上。
        if ($setting === null) {
            return back()->withErrors([
                'question' => $locale === 'en'
                    ? 'Add an AI model under Settings before using stock Q&A.'
                    : '請先到「系統設定」新增 AI 模型，才能使用個股問答。',
            ]);
        }

        // 同一檔同時只准一題未回答。這不只是限流，更是正確性問題：historyBefore()
        // 只取 completed，Q1 還沒答完就送 Q2 的話，Q2 會拿到缺一輪的歷史，
        // 「那風險呢」就會指向錯的前文。
        $pending = $user->stockChatTurns()
            ->where('instrument_id', $instrument->id)
            ->where('status', AnalysisStatus::Pending->value)
            ->exists();

        if ($pending) {
            return back()->withErrors([
                'question' => $locale === 'en'
                    ? 'The previous question is still being answered. Wait for it to finish before sending the next one.'
                    : '上一題還在回答中，請等它完成後再送出下一題。',
            ]);
        }

        $model = trim((string) ($data['model'] ?? '')) ?: (string) $setting->model;

        $turn = $user->stockChatTurns()->create([
            'instrument_id' => $instrument->id,
            'provider_type' => 'pending',
            'model' => $model,
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'question' => trim($data['question']),
            'answer' => null,
            'metadata' => [],
            'data_as_of' => CarbonImmutable::now(),
        ]);

        RunStockChatReply::dispatch($turn->id, $setting->id, $model, $locale);

        return redirect()->route('stocks.search', ['symbol' => $instrument->symbol]);
    }

    /**
     * 刪除單一回合。
     *
     * pending 的也能刪：job 找不到紀錄就直接結束，等同取消這次提問。
     */
    public function destroy(Request $request, StockChatTurn $stockChatTurn): RedirectResponse
    {
        abort_unless($stockChatTurn->user_id === $request->user()->id, 403);

        $symbol = $stockChatTurn->instrument?->symbol;

        $stockChatTurn->delete();

        return $symbol === null
            ? redirect()->route('stocks.search')
            : redirect()->route('stocks.search', ['symbol' => $symbol]);
    }

    /** 清空該檔的對話。走關聯查詢，天然帶 user_id 條件，不可能刪到別人的。 */
    public function clear(Request $request, Instrument $instrument): RedirectResponse
    {
        $request->user()
            ->stockChatTurns()
            ->where('instrument_id', $instrument->id)
            ->delete();

        return redirect()->route('stocks.search', ['symbol' => $instrument->symbol]);
    }

    private function resolveSetting(User $user, ?int $settingId): ?LlmProviderSetting
    {
        if ($settingId === null) {
            return $user->defaultLlmSetting();
        }

        $setting = $user->llmProviderSettings()->whereKey($settingId)->first();
        abort_if($setting === null, 403);

        return $setting;
    }
}
