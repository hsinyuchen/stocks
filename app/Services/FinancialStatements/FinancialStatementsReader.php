<?php

namespace App\Services\FinancialStatements;

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
            'isStale' => $this->isStale($periods),
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
     * @param  list<FinancialStatement>  $periods
     */
    private function isStale(array $periods): bool
    {
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
