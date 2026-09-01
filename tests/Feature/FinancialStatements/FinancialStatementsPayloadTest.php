<?php

namespace Tests\Feature\FinancialStatements;

use App\Enums\PeriodType;
use App\Models\FinancialStatement;
use App\Models\Instrument;
use App\Services\FinancialStatements\FinancialStatementsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialStatementsPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function instrument(string $symbol = 'RGTI', string $market = 'us'): Instrument
    {
        // MarketRegion 是 backed enum，backing value 是大寫 'US'/'TW'
        // （MarketRegion::from('us') 會拋 ValueError）。這裡用 strtoupper()
        // 讓呼叫端仍可以寫習慣的小寫 'us'/'tw'。
        return Instrument::factory()->create(['symbol' => $symbol, 'market' => strtoupper($market)]);
    }

    /**
     * @param  array<string, float|null>  $values
     */
    private function row(Instrument $instrument, int $year, int $quarter, array $values, string $currency = 'USD'): FinancialStatement
    {
        return FinancialStatement::create([
            'instrument_id' => $instrument->id,
            'period_type' => 'quarter',
            'fiscal_year' => $year,
            'fiscal_quarter' => $quarter,
            'period_label' => $year.'Q'.$quarter,
            'period_start' => sprintf('%d-%02d-01', $year, ($quarter - 1) * 3 + 1),
            'period_end' => sprintf('%d-%02d-28', $year, $quarter * 3),
            'fiscal_year_complete' => true,
            'currency' => $currency,
            'source' => 'sec',
            'income_fetched_at' => now(),
            'balance_fetched_at' => now(),
            'cashflow_fetched_at' => now(),
            ...$values,
        ]);
    }

    private function build(Instrument $instrument, bool $expanded = false): array
    {
        return app(FinancialStatementsPayload::class)
            ->build($instrument, PeriodType::Quarter, $expanded);
    }

    /**
     * @param  array<string, float|null>  $values
     */
    private function annualRow(Instrument $instrument, int $year, array $values): FinancialStatement
    {
        return FinancialStatement::create([
            'instrument_id' => $instrument->id,
            'period_type' => 'annual',
            'fiscal_year' => $year,
            'fiscal_quarter' => 0,
            'period_label' => (string) $year,
            'period_start' => sprintf('%d-01-01', $year),
            'period_end' => sprintf('%d-12-31', $year),
            'fiscal_year_complete' => true,
            'currency' => 'USD',
            'source' => 'sec',
            'income_fetched_at' => now(),
            'balance_fetched_at' => now(),
            'cashflow_fetched_at' => now(),
            ...$values,
        ]);
    }

    private function buildAnnual(Instrument $instrument, bool $expanded = false): array
    {
        return app(FinancialStatementsPayload::class)
            ->build($instrument, PeriodType::Annual, $expanded);
    }

    public function test_eps_is_never_scaled(): void
    {
        // 每股盈餘是每股金額，除以 1e6 會變成毫無意義的數字。
        $instrument = $this->instrument();
        $this->row($instrument, 2026, 1, ['revenue' => 5138000, 'eps_basic' => -0.0234, 'eps_diluted' => -0.0234]);

        $payload = $this->build($instrument);
        $period = $payload['periods'][0];

        $this->assertSame(1000000, $payload['unit']['scale']);
        $this->assertSame(5.138, $period['values']['revenue']);
        $this->assertSame(-0.0234, $period['values']['eps_basic'], 'EPS 不得被縮放');
        $this->assertSame(-0.0234, $period['values']['eps_diluted']);
    }

    public function test_every_period_shares_one_scale(): void
    {
        // 表格的欄要能互相比較，逐欄各自挑倍率會讓讀者比錯。
        $instrument = $this->instrument();
        $this->row($instrument, 2025, 4, ['revenue' => 1868000]);
        $this->row($instrument, 2026, 1, ['revenue' => 2000000000]);   // 20 億 → 觸發 billions

        $payload = $this->build($instrument);

        $this->assertSame(1000000000, $payload['unit']['scale']);
        $this->assertSame('financials.unit.billionUsd', $payload['unit']['key']);
        // 兩期都用同一個倍率，小的那期變成 0.001868 而不是自己換一個倍率。
        $values = array_column(array_column($payload['periods'], 'values'), 'revenue');
        $this->assertEqualsWithDelta(2.0, $values[0], 0.0001);
        $this->assertEqualsWithDelta(0.001868, $values[1], 0.0000001);
    }

    public function test_billion_threshold_is_inclusive_at_exactly_one_billion(): void
    {
        // unitFor() 用 `>= 1_000_000_000`。既有測試只餵過 2e9，從沒測過
        // 恰好等於門檻的值——把 `>=` 改成 `>` 也會全綠。這裡用剛好
        // 1_000_000_000 釘住邊界：等於門檻也要算進 billions，不能只有大於。
        $instrument = $this->instrument();
        $this->row($instrument, 2026, 1, ['revenue' => 1_000_000_000]);

        $payload = $this->build($instrument);

        $this->assertSame(1_000_000_000, $payload['unit']['scale']);
        $this->assertSame('financials.unit.billionUsd', $payload['unit']['key']);
    }

    public function test_taiwan_uses_hundred_million_regardless_of_magnitude(): void
    {
        // 新台幣的億元是台灣財經媒體的通用單位，再往上分級不符合閱讀習慣。
        $instrument = $this->instrument('2330.TW', 'tw');
        $this->row($instrument, 2024, 4, ['revenue' => 868461178000], 'TWD');

        $payload = $this->build($instrument);

        $this->assertSame(100000000, $payload['unit']['scale']);
        $this->assertSame('financials.unit.hundredMillionTwd', $payload['unit']['key']);
        $this->assertEqualsWithDelta(8684.61178, $payload['periods'][0]['values']['revenue'], 0.0001);
    }

    public function test_null_stays_null_and_zero_stays_zero(): void
    {
        // 財報上「該科目無資料」與「該科目為 0」是兩件事。
        $instrument = $this->instrument();
        $this->row($instrument, 2026, 1, ['revenue' => 0, 'net_income' => null]);

        $values = $this->build($instrument)['periods'][0]['values'];

        $this->assertSame(0.0, $values['revenue']);
        $this->assertNull($values['net_income']);
    }

    public function test_values_are_floats_not_strings(): void
    {
        // MySQL 的 decimal 讀回來是字串、sqlite 是 int/float。前端要的是數字，
        // 而所有測試都在 sqlite 上跑——這條在 sqlite 上也要能抓到型別問題。
        $instrument = $this->instrument();
        $this->row($instrument, 2026, 1, ['revenue' => 5138000]);

        $value = $this->build($instrument)['periods'][0]['values']['revenue'];

        $this->assertIsFloat($value);

        // 上面 5138000 / 1000000 = 5.138，除不盡，PHP 的 `/` 運算子本來就會
        // 回傳 float——就算拿掉實作裡的 (float) 顯式轉型，這個斷言仍然會過，
        // 對「有沒有做轉型」沒有偵測力。這裡另外挑一個整除的值
        // （5000000 / 1000000 = 5，整數結果），只有顯式 (float) 轉型才會讓
        // 它保持 float 而不是被 PHP 隱式退回 int——這才是這條測試名稱真正要驗的東西。
        $instrument2 = $this->instrument('AAPL', 'us');
        $this->row($instrument2, 2026, 1, ['revenue' => 5000000]);

        $exactValue = $this->build($instrument2)['periods'][0]['values']['revenue'];

        $this->assertIsFloat($exactValue);
        $this->assertSame(5.0, $exactValue);
    }

    public function test_periods_are_newest_first(): void
    {
        $instrument = $this->instrument();
        $this->row($instrument, 2025, 4, ['revenue' => 1]);
        $this->row($instrument, 2026, 1, ['revenue' => 2]);

        $labels = array_column($this->build($instrument)['periods'], 'label');

        $this->assertSame(['2026Q1', '2025Q4'], $labels);
    }

    public function test_default_shows_eight_quarters_and_reports_the_total(): void
    {
        $instrument = $this->instrument();
        foreach (range(1, 12) as $i) {
            $this->row($instrument, 2024 + intdiv($i - 1, 4), ($i - 1) % 4 + 1, ['revenue' => $i]);
        }

        $payload = $this->build($instrument);

        $this->assertCount(8, $payload['periods']);
        $this->assertSame(8, $payload['shownCount']);
        $this->assertSame(12, $payload['totalCount'], 'totalCount 是資料庫實際筆數，不受 limit 影響');
        $this->assertFalse($payload['expanded']);
    }

    public function test_expanded_shows_up_to_the_configured_depth(): void
    {
        $instrument = $this->instrument();
        foreach (range(1, 12) as $i) {
            $this->row($instrument, 2024 + intdiv($i - 1, 4), ($i - 1) % 4 + 1, ['revenue' => $i]);
        }

        $payload = $this->build($instrument, expanded: true);

        $this->assertCount(12, $payload['periods']);
        $this->assertTrue($payload['expanded']);
    }

    public function test_taiwan_reports_the_not_disclosed_fields(): void
    {
        // 制度性不揭露與「公司無此項」都是 null，光看值分不出來——
        // 前端靠這份清單決定顯示「此市場不單獨揭露」還是「—」。
        $instrument = $this->instrument('2330.TW', 'tw');
        $this->row($instrument, 2024, 4, ['revenue' => 1], 'TWD');

        $this->assertSame(
            (array) config('financial_statements.tw_not_disclosed'),
            $this->build($instrument)['notDisclosed']
        );
    }

    public function test_us_has_no_not_disclosed_fields(): void
    {
        $instrument = $this->instrument();
        $this->row($instrument, 2026, 1, ['revenue' => 1]);

        $this->assertSame([], $this->build($instrument)['notDisclosed']);
    }

    public function test_period_markers_are_passed_through(): void
    {
        $instrument = $this->instrument();
        $row = $this->row($instrument, 2025, 4, ['revenue' => 1]);
        $row->update([
            'fiscal_year_complete' => false,
            'income_derivation' => 'mixed',
            'cashflow_derivation' => 'derived',
            'income_restatement_mixed' => true,
        ]);

        $period = $this->build($instrument)['periods'][0];

        $this->assertFalse($period['fiscalYearComplete']);
        $this->assertSame('mixed', $period['incomeDerivation']);
        $this->assertSame('derived', $period['cashflowDerivation']);
        $this->assertTrue($period['incomeRestatementMixed']);
        $this->assertFalse($period['cashflowRestatementMixed']);
    }

    public function test_length_days_is_computed_from_the_period_bounds(): void
    {
        // 期間長度不同（例如 COST 的 16 週 Q4）要在畫面上標記出來。
        $instrument = $this->instrument();
        $row = $this->row($instrument, 2017, 4, ['revenue' => 1]);
        $row->update(['period_start' => '2017-05-15', 'period_end' => '2017-09-03']);

        $this->assertSame(111, $this->build($instrument)['periods'][0]['lengthDays']);
    }

    public function test_empty_table_still_returns_a_usable_shape(): void
    {
        // state 為 absent 時前端仍要能渲染骨架，不能因為缺鍵而炸掉。
        $payload = $this->build($this->instrument());

        $this->assertSame([], $payload['periods']);
        $this->assertSame(0, $payload['totalCount']);
        $this->assertSame('absent', $payload['state']);
        $this->assertArrayHasKey('scale', $payload['unit']);
        // 空清單時 largestAmount() 回 0.0，unitFor() 沒有任何一筆金額可比較，
        // 只能落在預設的百萬美元那一階——把這個值釘死，否則翻轉
        // unitFor() 的 millions/billions 分支這條測試也不會發現。
        $this->assertSame(1_000_000, $payload['unit']['scale']);
        $this->assertSame('financials.unit.millionUsd', $payload['unit']['key']);
    }

    public function test_taiwan_market_value_is_matched_case_insensitively(): void
    {
        // MarketRegion 的 backing value 是大寫 'TW'。實作若直接拿
        // $instrument->market->value 跟小寫 'tw' 比對會永遠比對失敗，
        // 導致台股被誤判成美股倍率階梯——這條專門釘住這個大小寫陷阱。
        $instrument = $this->instrument('2330.TW', 'tw');
        $this->assertSame('TW', $instrument->market->value);

        $payload = $this->build($instrument);

        $this->assertSame('financials.unit.hundredMillionTwd', $payload['unit']['key']);
    }

    public function test_default_shows_five_years_for_annual_period(): void
    {
        // brief 附的測試集只餵過 PeriodType::Quarter，limitFor() 對 Annual
        // 分支（DEFAULT_YEARS 常數）完全零覆蓋——自加變異證實：把
        // DEFAULT_YEARS 改成 999 不會讓任何既有測試變紅。這條專門釘住它。
        $instrument = $this->instrument();
        foreach (range(1, 7) as $i) {
            $this->annualRow($instrument, 2017 + $i, ['revenue' => $i]);
        }

        $payload = $this->buildAnnual($instrument);

        $this->assertCount(5, $payload['periods']);
        $this->assertSame(5, $payload['shownCount']);
        $this->assertSame(7, $payload['totalCount']);
        $this->assertSame('annual', $payload['periodType']);
    }

    public function test_expanded_shows_ten_years_for_annual_period(): void
    {
        // 同上，補上展開路徑（config('financial_statements.years') = 10）的覆蓋。
        $instrument = $this->instrument();
        foreach (range(1, 12) as $i) {
            $this->annualRow($instrument, 2012 + $i, ['revenue' => $i]);
        }

        $payload = $this->buildAnnual($instrument, expanded: true);

        $this->assertCount(10, $payload['periods']);
        $this->assertTrue($payload['expanded']);
    }
}
