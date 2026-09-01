<?php

namespace App\Services\FinancialStatements;

use App\Console\Commands\WarmFinancialStatements;
use App\Enums\PeriodType;
use App\Models\FinancialStatement;
use App\Models\FinancialStatementFetch;
use App\Models\Instrument;

/**
 * 財報的只讀入口。**只查資料表，不派工、不抓取。**
 *
 * 讀取路徑順手派工會讓每一次頁面渲染都可能觸發外部請求，包括爬蟲與預覽卡片的
 * 請求。派工是 FinancialStatementDispatcher 的事，由 controller 明確呼叫。
 *
 * state 的六態裡有兩個是衍生的：fetching 與 refreshing 的 DB status 都在
 * {queued, running}，差別只在表裡有沒有列。有列時畫面該顯示舊資料並標「更新中」，
 * 退回骨架等於把已經有的資訊藏起來。
 */
class FinancialStatementsReader
{
    /**
     * @return array{periods: list<FinancialStatement>, state: string, isStale: bool, errorCategory: ?string}
     *
     * $limit 與 config('financial_statements.quarters'/'years')（擷取深度）是兩個獨立的數字，
     * 呼叫端沒有義務讓兩者相等。$limit 可以大於擷取深度（例如日後想在 UI 多顯示幾期歷史），
     * 這在 isStale() 改成跨列取 max 之後是安全的——見該方法 docblock。
     */
    public function for(Instrument $instrument, PeriodType $type, int $limit): array
    {
        $periods = FinancialStatement::query()
            ->where('instrument_id', $instrument->id)
            ->where('period_type', $type->value)
            ->orderByDesc('fiscal_year')
            ->orderByDesc('fiscal_quarter')
            ->limit(max(1, $limit))
            ->get()
            ->all();

        $fetch = FinancialStatementFetch::query()
            ->where('instrument_id', $instrument->id)
            ->first();

        return [
            'periods' => $periods,
            'state' => $this->state($periods, $fetch),
            'isStale' => self::isStale($periods),
            'errorCategory' => $fetch?->error_category,
        ];
    }

    /**
     * @param  list<FinancialStatement>  $periods
     */
    private function state(array $periods, ?FinancialStatementFetch $fetch): string
    {
        if ($fetch === null) {
            return $periods === [] ? 'absent' : 'ready';
        }

        if ($fetch->isInFlight()) {
            return $periods === [] ? 'fetching' : 'refreshing';
        }

        return match ($fetch->status) {
            // 有舊列時使用者手上有可看的資料，一次抓取失敗不該把畫面整個換成
            // 錯誤頁；errorCategory 仍在 for() 回傳，讓 UI 標一行「最近一次更新失敗」。
            'failed' => $periods === [] ? 'failed' : 'ready',
            // 刻意不對稱：unsupported 無條件回 'unsupported'，不像 failed 那樣看
            // 有沒有舊列。可達路徑：某檔美股先成功落地過去 20 季，之後 SEC
            // ticker map 查不到、或有人把 asset_type 從 stock 更正成 etf，
            // 判定轉成 unsupported——這時資料庫裡其實還有真實、能看的財報，
            // 但畫面上會被判成「不支援」，UI 依 state 直接分支的話這批舊資料
            // 對使用者變成不可見，且至少要等 unsupported 的 7 天退避到期、
            // 下一次成功重抓才會恢復。這不是錯誤，是刻意選擇「asset_type 這類
            // 判定變更應該立刻反映在畫面上」優先於「盡量沿用舊資料」，只是
            // 沒有測試會抓到這個取捨，記在這裡讓下一個改 state() 的人知道。
            'unsupported' => 'unsupported',
            default => $periods === [] ? 'absent' : 'ready',
        };
    }

    /**
     * 跨列先取 max、再跨欄取 min：任一張表「最近一次被刷新的時間」早於門檻，就算過期。
     *
     * 不能跨列取 min（逐列逐欄檢查任何一列過期就整體過期）。原因是
     * FinancialStatementWriter::reconcileQuarters() 刻意只刪「擷取視窗內、本次未產出」的
     * 列，視窗外的歷史列永遠不會被本次抓取觸碰、fetched_at 永遠凍結在建立當下。一旦
     * 資料表累積的列數超過擷取深度、而呼叫端的 $limit 又大於該深度，這些凍結列就會被
     * `for()` 撈進 $periods，逐列取 min 會讓 isStale 恆為 true——即使剛剛才抓過。
     * 語意上也對：歷史季度的數字不會變，「過期」該問的是「我們最近一次成功刷新是多久
     * 以前」，不是「最舊那一列的時間戳多舊」。跨欄仍取 min：三張表（損益／資產負債／
     * 現金流）任一張沒被最近刷新過，就代表這批資料不是同一次成功抓取的產物，仍算過期。
     *
     * 某一欄在所有列都是 null，視同該欄的 max 是 null——這張表從沒抓成功過，比
     * 「30 天前抓的」更舊，一律算過期。
     *
     * 空陣列回傳 false（「不算過期」），這是本方法對「無資料」的中性表態，不是
     * 「新鮮」的意思——呼叫端若要用這個結果推斷「要不要重抓」，必須自己先判斷
     * 有沒有資料。{@see FinancialStatementDispatcher::isFresh()} 就是因為這個
     * 陷阱才沒有直接把 `! isStale($periods)` 當作新鮮度用。
     *
     * public static：{@see FinancialStatementDispatcher::claim()} 判斷
     * succeeded 是否需要因為新鮮度過期而重派工時，用的是同一套規則
     * （spec 明文「succeeded 由表列的 fetched_at 與 30 天新鮮度決定」），
     * {@see WarmFinancialStatements::skipReason()} 也是
     * ——三處各自維護一份判準才是真正的風險。
     *
     * 但共用判準不等於共用輸入，這兩件事不要混為一談。三個呼叫端餵進來的
     * $periods 範圍刻意不同：{@see for()} 自己餵「單一 period_type、且被
     * limit 截斷」的列，問的是「這個分頁上的資料過期了嗎」；
     * {@see FinancialStatementDispatcher::isFresh()} 與
     * {@see WarmFinancialStatements} 餵的是「不篩 period_type、不 limit」
     * 的全部列，問的是「這檔標的要不要重抓」。前者是單一分頁的事，後者是
     * 整檔的事，兩個問題本來就該用不同範圍的輸入回答——把兩邊改成餵同一種
     * 輸入不會讓語意更一致，只會讓其中一邊答錯自己的問題。下次有人想「統一」
     * 這個輸入之前，請先讀完這一段。
     *
     * 這個不對稱會在特定情境下讓兩邊給出不同結論：擷取視窗滑動導致某年度的
     * annual 列被排除出本次 reconcileAnnuals() 的產出集合（見該方法
     * docblock），該列的 *_fetched_at 因此凍結在建立當下。dispatcher／
     * 預熱指令跨全部列取 max，會被同一次抓取裡新鮮的 quarter 列蓋過去、
     * 判「新鮮」；`for()` 只看 annual 列，會判「過期」。這不是需要修的分歧
     * ——兩邊都答對了各自的問題：重抓救不了那個被凍結的 annual 列
     * （reconciliation 刻意不動視窗外的列，這是防止邊界啃蝕的正確設計），
     * 而那筆 annual 資料確實是舊的。畫面上這個情境要怎麼呈現（例如年度分頁
     * 標「資料源已停止更新此期間」而不是「更新中」）留給子專案 3 決定，不是
     * 這個方法、也不是這一層要修的東西。
     *
     * @param  iterable<FinancialStatement>  $periods
     */
    public static function isStale(iterable $periods): bool
    {
        $periods = is_array($periods) ? $periods : iterator_to_array($periods);

        if ($periods === []) {
            return false;
        }

        $cutoff = now()->subDays((int) config('financial_statements.freshness_days'));

        foreach (['income_fetched_at', 'balance_fetched_at', 'cashflow_fetched_at'] as $column) {
            $latest = null;

            foreach ($periods as $period) {
                $value = $period->{$column};

                if ($value !== null && ($latest === null || $value->gt($latest))) {
                    $latest = $value;
                }
            }

            if ($latest === null || $latest->lt($cutoff)) {
                return true;
            }
        }

        return false;
    }
}
