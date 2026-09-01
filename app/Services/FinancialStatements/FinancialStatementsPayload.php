<?php

namespace App\Services\FinancialStatements;

use App\Enums\PeriodType;
use App\Models\FinancialStatement;
use App\Models\Instrument;

/**
 * 把 Reader 的輸出轉成子頁面的契約。
 *
 * 這一層存在的理由是「有判斷的東西要測得到」：本專案沒有 JS test runner
 * （見 tests/Feature/I18nMessageParityTest.php 的說明），放在 React 裡的縮放、
 * 不揭露判定、期間標記全部等於零覆蓋。所以那些判斷留在 PHP，前端只渲染。
 */
class FinancialStatementsPayload
{
    /** 未展開時的期數。展開後改用 config 的完整深度。 */
    private const DEFAULT_QUARTERS = 8;

    private const DEFAULT_YEARS = 5;

    public function __construct(private readonly FinancialStatementsReader $reader) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Instrument $instrument, PeriodType $type, bool $expanded = false): array
    {
        $limit = $this->limitFor($type, $expanded);
        $read = $this->reader->for($instrument, $type, $limit);

        /** @var list<FinancialStatement> $rows */
        $rows = $read['periods'];

        // MarketRegion 的 backing value 是大寫 'TW'/'US'（enum cast 讀出來是
        // enum 實例，->value 才是字串）。這裡統一轉小寫再比對，
        // 否則跟下面的 'tw' 字面值永遠比對不到，台股會被誤判成美股倍率階梯。
        $market = strtolower($instrument->market?->value ?? 'us');
        $unit = $this->unitFor($market, $rows);

        return [
            'periodType' => $type->value,
            'state' => $read['state'],
            'errorCategory' => $read['errorCategory'],
            'isStale' => $read['isStale'],
            'expanded' => $expanded,
            'shownCount' => count($rows),
            'totalCount' => $this->totalCount($instrument, $type),
            'unit' => $unit,
            'notDisclosed' => $market === 'tw'
                ? array_values((array) config('financial_statements.tw_not_disclosed'))
                : [],
            'periods' => array_map(
                fn (FinancialStatement $row): array => $this->period($row, $unit['scale']),
                $rows
            ),
        ];
    }

    private function limitFor(PeriodType $type, bool $expanded): int
    {
        if (! $expanded) {
            return $type === PeriodType::Annual ? self::DEFAULT_YEARS : self::DEFAULT_QUARTERS;
        }

        return $type === PeriodType::Annual
            ? (int) config('financial_statements.years')
            : (int) config('financial_statements.quarters');
    }

    private function totalCount(Instrument $instrument, PeriodType $type): int
    {
        return FinancialStatement::query()
            ->where('instrument_id', $instrument->id)
            ->where('period_type', $type->value)
            ->count();
    }

    /**
     * 整份 payload 共用一個倍率。
     *
     * 逐欄各自挑倍率會讓表格的欄無法互相比較——而「並排比較」正是這張表的用途。
     * 台股只有億元一階：那是台灣財經媒體的通用單位，再往上分級反而不符閱讀習慣。
     *
     * @param  list<FinancialStatement>  $rows
     * @return array{scale: int, key: string}
     */
    private function unitFor(string $market, array $rows): array
    {
        if ($market === 'tw') {
            return ['scale' => 100_000_000, 'key' => 'financials.unit.hundredMillionTwd'];
        }

        return $this->largestAmount($rows) >= 1_000_000_000
            ? ['scale' => 1_000_000_000, 'key' => 'financials.unit.billionUsd']
            : ['scale' => 1_000_000, 'key' => 'financials.unit.millionUsd'];
    }

    /**
     * @param  list<FinancialStatement>  $rows
     */
    private function largestAmount(array $rows): float
    {
        $largest = 0.0;

        foreach ($rows as $row) {
            foreach ($this->scaledFields() as $field) {
                $value = $row->{$field};

                if ($value !== null) {
                    $largest = max($largest, abs((float) $value));
                }
            }
        }

        return $largest;
    }

    /**
     * @return array<string, mixed>
     */
    private function period(FinancialStatement $row, int $scale): array
    {
        $values = [];

        foreach ($this->scaledFields() as $field) {
            $raw = $row->{$field};
            // null 不得變成 0：財報上「無資料」與「為 0」是兩件事。
            $values[$field] = $raw === null ? null : (float) $raw / $scale;
        }

        foreach ($this->epsFields() as $field) {
            // 每股金額不縮放——除以 1e6 之後的 EPS 沒有任何意義。
            $raw = $row->{$field};
            $values[$field] = $raw === null ? null : (float) $raw;
        }

        return [
            'label' => $row->period_label,
            'start' => $row->period_start->toDateString(),
            'end' => $row->period_end->toDateString(),
            'lengthDays' => (int) $row->period_start->diffInDays($row->period_end),
            'fiscalYearComplete' => (bool) $row->fiscal_year_complete,
            'incomeDerivation' => $row->income_derivation->value,
            'cashflowDerivation' => $row->cashflow_derivation->value,
            'incomeRestatementMixed' => (bool) $row->income_restatement_mixed,
            'cashflowRestatementMixed' => (bool) $row->cashflow_restatement_mixed,
            'values' => $values,
        ];
    }

    /** 31 個要縮放的金額科目（33 扣掉兩個 EPS）。 */
    private function scaledFields(): array
    {
        return array_merge(
            (array) config('financial_statements.income_fields'),
            (array) config('financial_statements.instant_fields'),
            (array) config('financial_statements.cashflow_fields'),
        );
    }

    private function epsFields(): array
    {
        return array_keys((array) config('financial_statements.sec_eps_tags'));
    }
}
