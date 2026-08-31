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
     * 整體新鮮度取三個 *_fetched_at 的最小值：只要有一張表過期，整列就算過期。
     *
     * 逐列逐欄檢查而非只看最新一列，理由是 FinancialStatementWriter 每次寫入會
     * 把本次產出的所有列（含歷史列）的三個 fetched_at 同步刷新成同一個時間戳，
     * 所以「回傳集合裡任何一列的任何一欄過期」與「整批資料是同一次抓取產生」是
     * 等價的檢查；null 視為過期——代表這張表從沒抓成功過，比「30 天前抓的」更舊。
     *
     * @param  list<FinancialStatement>  $periods
     */
    private function isStale(array $periods): bool
    {
        if ($periods === []) {
            return false;
        }

        $cutoff = now()->subDays((int) config('financial_statements.freshness_days'));

        foreach ($periods as $period) {
            foreach (['income_fetched_at', 'balance_fetched_at', 'cashflow_fetched_at'] as $column) {
                if ($period->{$column} === null || $period->{$column}->lt($cutoff)) {
                    return true;
                }
            }
        }

        return false;
    }
}
