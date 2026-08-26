<?php

namespace Tests\Unit;

use App\Data\FundamentalsData;
use App\Data\HealthBlockResult;
use App\Data\HealthInputSnapshot;
use App\Data\OrderInventoryMetrics;
use App\Enums\HealthBlock;
use App\Enums\HealthUnavailableReason;
use App\Enums\HealthVerdict;
use App\Services\Health\LongTermHealthReader;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * 中長線四塊判定。
 *
 * **每塊四態各有一條測試**（Positive／Neutral／Negative／null，共 16 條）：
 * 少了 Neutral 那條，把「未達正面門檻」直接判成 Negative 的實作照樣全綠，
 * 而那正是本框架五個階段一路在防的失效模式。
 *
 * 測資一律**由 config 門檻推導**（中性取兩門檻中點、正負各外推一段），
 * 不寫死數字——門檻調整時測試該跟著移動，而不是變成一組與判準無關的常數。
 */
class LongTermHealthReaderTest extends TestCase
{
    // ---------- 估值 ----------

    #[Test]
    public function valuation_is_positive_when_both_percentiles_sit_below_the_cheap_threshold(): void
    {
        $cheap = $this->threshold('valuation.cheap_percentile');

        $block = $this->block(HealthBlock::Valuation, [
            'valuationPercentiles' => $this->percentiles(per: $cheap - 20.0, pbr: $cheap - 15.0),
        ]);

        $this->assertSame(HealthVerdict::Positive, $block->verdict);
    }

    /**
     * 兩者都落在中間帶。**這條不可省**：沒有它，把中間帶併進負面的實作全綠。
     * 中點刻意不等於任一門檻，所以邊界寫成 `>` 或 `>=` 都不影響這條，
     * 它只針對「中性帶存在」這一件事。
     */
    #[Test]
    public function valuation_is_neutral_when_both_percentiles_sit_between_the_thresholds(): void
    {
        $mid = ($this->threshold('valuation.cheap_percentile') + $this->threshold('valuation.expensive_percentile')) / 2;

        $block = $this->block(HealthBlock::Valuation, [
            'valuationPercentiles' => $this->percentiles(per: $mid, pbr: $mid),
        ]);

        $this->assertSame(HealthVerdict::Neutral, $block->verdict);
    }

    #[Test]
    public function valuation_is_negative_when_both_percentiles_sit_above_the_expensive_threshold(): void
    {
        $expensive = $this->threshold('valuation.expensive_percentile');

        $block = $this->block(HealthBlock::Valuation, [
            'valuationPercentiles' => $this->percentiles(per: $expensive + 20.0, pbr: $expensive + 15.0),
        ]);

        $this->assertSame(HealthVerdict::Negative, $block->verdict);
    }

    /** 每檔每日一列、需 ≥20 列，實測最多的一檔只有 3 列——是「還沒累積」不是「算不出來」。 */
    #[Test]
    public function valuation_is_unavailable_as_not_yet_when_no_percentile_has_enough_samples(): void
    {
        $block = $this->block(HealthBlock::Valuation, [
            'valuationPercentiles' => null,
            'fundamentalsAsOf' => '2026-08-05',
        ]);

        $this->assertNull($block->verdict);
        $this->assertSame(HealthUnavailableReason::NotYet, $block->unavailableReason);
        $this->assertSame('2026-08-05', $block->asOf);
    }

    /** 恰好等於便宜門檻仍是 Positive（判準是 `<=`）。把它改成 `<` 這條就紅。 */
    #[Test]
    public function valuation_treats_the_cheap_threshold_itself_as_positive(): void
    {
        $cheap = $this->threshold('valuation.cheap_percentile');

        $block = $this->block(HealthBlock::Valuation, [
            'valuationPercentiles' => $this->percentiles(per: $cheap, pbr: $cheap),
        ]);

        $this->assertSame(HealthVerdict::Positive, $block->verdict);
    }

    /** 恰好等於昂貴門檻仍是 Negative（判準是 `>=`）。把它改成 `>` 這條就紅。 */
    #[Test]
    public function valuation_treats_the_expensive_threshold_itself_as_negative(): void
    {
        $expensive = $this->threshold('valuation.expensive_percentile');

        $block = $this->block(HealthBlock::Valuation, [
            'valuationPercentiles' => $this->percentiles(per: $expensive, pbr: $expensive),
        ]);

        $this->assertSame(HealthVerdict::Negative, $block->verdict);
    }

    /**
     * 一便宜一昂貴 → Neutral，且理由要說出「兩者反向」。
     *
     * **不可斷言成 Positive 或 Negative**：證據互相矛盾時說「看不出偏向」才是實話。
     * 測資刻意讓 PER 為正面、PBR 為負面且兩者不對稱，「改成取 PER」「改成取 PBR」
     * 「取平均」三種偷懶實作的答案各不相同，這條都能分出來。
     */
    #[Test]
    public function valuation_falls_back_to_neutral_when_the_two_percentiles_point_in_opposite_directions(): void
    {
        $cheap = $this->threshold('valuation.cheap_percentile');
        $expensive = $this->threshold('valuation.expensive_percentile');

        $block = $this->block(HealthBlock::Valuation, [
            'valuationPercentiles' => $this->percentiles(per: $cheap - 20.0, pbr: $expensive + 20.0),
        ]);

        $this->assertSame(HealthVerdict::Neutral, $block->verdict);
        $this->assertReasonContains($block, '方向相反');
    }

    /** 只有一項算得出來時照樣判定，但理由必須寫明「僅依」哪一項。 */
    #[Test]
    public function valuation_still_decides_on_a_single_percentile_and_says_which_one(): void
    {
        $cheap = $this->threshold('valuation.cheap_percentile');

        $block = $this->block(HealthBlock::Valuation, [
            'valuationPercentiles' => $this->percentiles(per: $cheap - 20.0, pbr: null),
        ]);

        $this->assertSame(HealthVerdict::Positive, $block->verdict);
        $this->assertNull($block->unavailableReason);
        $this->assertReasonContains($block, '僅依本益比');
    }

    // ---------- ROE ----------

    #[Test]
    public function return_on_equity_is_positive_above_the_strong_threshold(): void
    {
        $block = $this->block(HealthBlock::ReturnOnEquity, [
            'fundamentals' => new FundamentalsData(roe: $this->threshold('roe.strong') + 5.0),
        ]);

        $this->assertSame(HealthVerdict::Positive, $block->verdict);
    }

    /** 中間帶。少了這條，「未達 strong 一律負」的實作照樣全綠。 */
    #[Test]
    public function return_on_equity_is_neutral_between_the_thresholds(): void
    {
        $mid = ($this->threshold('roe.weak') + $this->threshold('roe.strong')) / 2;

        $block = $this->block(HealthBlock::ReturnOnEquity, [
            'fundamentals' => new FundamentalsData(roe: $mid),
        ]);

        $this->assertSame(HealthVerdict::Neutral, $block->verdict);
    }

    #[Test]
    public function return_on_equity_is_negative_below_the_weak_threshold(): void
    {
        $block = $this->block(HealthBlock::ReturnOnEquity, [
            'fundamentals' => new FundamentalsData(roe: $this->threshold('roe.weak') - 3.0),
        ]);

        $this->assertSame(HealthVerdict::Negative, $block->verdict);
    }

    /** 台股但還沒抓到 ROE：NotYet，等抓過就會有。與美股的 NotInUniverse 是兩件事。 */
    #[Test]
    public function return_on_equity_is_unavailable_as_not_yet_when_a_taiwan_stock_has_no_roe(): void
    {
        $block = $this->block(HealthBlock::ReturnOnEquity, [
            'symbol' => '2330.TW',
            'fundamentals' => new FundamentalsData(roe: null),
            'fundamentalsAsOf' => '2026-08-05',
        ]);

        $this->assertNull($block->verdict);
        $this->assertSame(HealthUnavailableReason::NotYet, $block->unavailableReason);
        $this->assertSame('2026-08-05', $block->asOf);
    }

    /**
     * 美股：NotInUniverse。FinMind 是唯一的真實 FundamentalsProvider，美股永遠不會有這欄。
     *
     * 與上一條配對——只測其中一種的話，把兩種成因合併成同一個值的實作照樣全綠，
     * 而「等一下就有」與「永遠不會有」對使用者是不同的行動。
     */
    #[Test]
    public function return_on_equity_is_unavailable_as_not_in_universe_for_a_us_stock(): void
    {
        $block = $this->block(HealthBlock::ReturnOnEquity, [
            'symbol' => 'AAPL',
            'market' => 'US',
            // 就算硬塞了 roe 也不該評估：美股沒有這個資料源。
            'fundamentals' => new FundamentalsData(roe: 30.0),
        ]);

        $this->assertNull($block->verdict);
        $this->assertSame(HealthUnavailableReason::NotInUniverse, $block->unavailableReason);
    }

    /** 恰好等於 strong 是 Positive；恰好等於 weak 是 Neutral（判準是 `< weak` 才負）。 */
    #[Test]
    public function return_on_equity_thresholds_include_their_own_boundary(): void
    {
        $atStrong = $this->block(HealthBlock::ReturnOnEquity, [
            'fundamentals' => new FundamentalsData(roe: $this->threshold('roe.strong')),
        ]);
        $atWeak = $this->block(HealthBlock::ReturnOnEquity, [
            'fundamentals' => new FundamentalsData(roe: $this->threshold('roe.weak')),
        ]);

        $this->assertSame(HealthVerdict::Positive, $atStrong->verdict);
        $this->assertSame(HealthVerdict::Neutral, $atWeak->verdict);
    }

    /**
     * ROE 已是百分比（FinMind 回的是 `TTM 淨利 / 股東權益 * 100`，實測最大值 50.89），
     * 理由不得再乘 100。少了這條，多乘一次 100 只會讓文案變成 1250.0%，判定卻照樣正確。
     */
    #[Test]
    public function return_on_equity_reason_reports_the_percentage_without_rescaling(): void
    {
        $block = $this->block(HealthBlock::ReturnOnEquity, [
            'fundamentals' => new FundamentalsData(roe: 12.5),
        ]);

        $this->assertReasonContains($block, '12.5%');
    }

    // ---------- 成長 ----------

    #[Test]
    public function growth_is_positive_above_the_strong_threshold(): void
    {
        $block = $this->block(HealthBlock::Growth, [
            'metrics' => new OrderInventoryMetrics(revenueYoy: $this->threshold('growth.strong') + 0.10),
        ]);

        $this->assertSame(HealthVerdict::Positive, $block->verdict);
    }

    /** 中間帶。少了這條，「未達 strong 一律負」的實作照樣全綠。 */
    #[Test]
    public function growth_is_neutral_between_the_thresholds(): void
    {
        $mid = ($this->threshold('growth.weak') + $this->threshold('growth.strong')) / 2;

        $block = $this->block(HealthBlock::Growth, [
            'metrics' => new OrderInventoryMetrics(revenueYoy: $mid),
        ]);

        $this->assertSame(HealthVerdict::Neutral, $block->verdict);
    }

    #[Test]
    public function growth_is_negative_below_the_weak_threshold(): void
    {
        $block = $this->block(HealthBlock::Growth, [
            'metrics' => new OrderInventoryMetrics(revenueYoy: $this->threshold('growth.weak') - 0.10),
        ]);

        $this->assertSame(HealthVerdict::Negative, $block->verdict);
    }

    /** 序列整個沒有：NotYet，跑過分析就會有。 */
    #[Test]
    public function growth_is_unavailable_as_not_yet_without_metrics(): void
    {
        $block = $this->block(HealthBlock::Growth, ['metrics' => null]);

        $this->assertNull($block->verdict);
        $this->assertSame(HealthUnavailableReason::NotYet, $block->unavailableReason);
    }

    /** 序列在但缺去年同期：Indeterminate，再跑幾次也不會變出來。與 NotYet 是兩件事。 */
    #[Test]
    public function growth_is_indeterminate_when_the_series_exists_but_year_over_year_cannot_be_computed(): void
    {
        $block = $this->block(HealthBlock::Growth, [
            'metrics' => new OrderInventoryMetrics(revenueYoy: null),
            'financialPeriod' => '2026-Q2',
        ]);

        $this->assertNull($block->verdict);
        $this->assertSame(HealthUnavailableReason::Indeterminate, $block->unavailableReason);
        $this->assertSame('2026-Q2', $block->asOf);
    }

    /** 恰好等於 strong 是 Positive；恰好等於 weak 是 Neutral。 */
    #[Test]
    public function growth_thresholds_include_their_own_boundary(): void
    {
        $atStrong = $this->block(HealthBlock::Growth, [
            'metrics' => new OrderInventoryMetrics(revenueYoy: $this->threshold('growth.strong')),
        ]);
        $atWeak = $this->block(HealthBlock::Growth, [
            'metrics' => new OrderInventoryMetrics(revenueYoy: $this->threshold('growth.weak')),
        ]);

        $this->assertSame(HealthVerdict::Positive, $atStrong->verdict);
        $this->assertSame(HealthVerdict::Neutral, $atWeak->verdict);
    }

    /**
     * 成長取的是 `OrderInventoryMetrics::$revenueYoy`（比率），
     * **不是** `FundamentalsData::$revenueYoy`（百分比）。兩者同名差 100 倍，
     * 接錯不會有任何錯誤訊息。這裡刻意讓兩個欄位指向不同判定：
     * 比率 0.05 是 Neutral，百分比 50.0 會變 Positive。
     */
    #[Test]
    public function growth_reads_the_ratio_field_not_the_percentage_field_of_the_same_name(): void
    {
        $mid = ($this->threshold('growth.weak') + $this->threshold('growth.strong')) / 2;

        $block = $this->block(HealthBlock::Growth, [
            'metrics' => new OrderInventoryMetrics(revenueYoy: $mid),
            'fundamentals' => new FundamentalsData(revenueYoy: 50.0),
        ]);

        $this->assertSame(HealthVerdict::Neutral, $block->verdict);
        // 比率轉成文案時要乘 100：0.075 → +7.5%。漏乘會印成 +0.1%。
        $this->assertReasonContains($block, sprintf('%+.1f%%', $mid * 100));
    }

    // ---------- 品質 ----------

    #[Test]
    public function quality_is_positive_when_both_signals_are_strong(): void
    {
        $block = $this->block(HealthBlock::Quality, [
            'metrics' => new OrderInventoryMetrics(
                dsoChangeDays: $this->threshold('quality.dso_change_days_better') - 5.0,
                ocfToNetIncome: $this->threshold('quality.ocf_to_net_income_strong') + 0.5,
            ),
        ]);

        $this->assertSame(HealthVerdict::Positive, $block->verdict);
    }

    /** 兩項都在中間帶。少了這條，「未達 strong 一律負」的實作照樣全綠。 */
    #[Test]
    public function quality_is_neutral_when_both_signals_sit_between_the_thresholds(): void
    {
        $ocfMid = ($this->threshold('quality.ocf_to_net_income_weak') + $this->threshold('quality.ocf_to_net_income_strong')) / 2;
        $dsoMid = ($this->threshold('quality.dso_change_days_better') + $this->threshold('quality.dso_change_days_worse')) / 2;

        $block = $this->block(HealthBlock::Quality, [
            'metrics' => new OrderInventoryMetrics(dsoChangeDays: $dsoMid, ocfToNetIncome: $ocfMid),
        ]);

        $this->assertSame(HealthVerdict::Neutral, $block->verdict);
    }

    #[Test]
    public function quality_is_negative_when_both_signals_are_weak(): void
    {
        $block = $this->block(HealthBlock::Quality, [
            'metrics' => new OrderInventoryMetrics(
                dsoChangeDays: $this->threshold('quality.dso_change_days_worse') + 10.0,
                ocfToNetIncome: $this->threshold('quality.ocf_to_net_income_weak') - 0.3,
            ),
        ]);

        $this->assertSame(HealthVerdict::Negative, $block->verdict);
    }

    /** 序列整個沒有：NotYet。 */
    #[Test]
    public function quality_is_unavailable_as_not_yet_without_metrics(): void
    {
        $block = $this->block(HealthBlock::Quality, [
            'industryBucket' => 'suited',
            'metrics' => null,
        ]);

        $this->assertNull($block->verdict);
        $this->assertSame(HealthUnavailableReason::NotYet, $block->unavailableReason);
    }

    /**
     * 產業不適用：NotApplicable，且**優先於任何算得出來的數字**。
     *
     * 測資刻意帶一組會判 Positive 的 metrics——忽略 industryBucket 的實作
     * 會回一個非 null 的判定，這條就紅。與上一條的 NotYet 配對，
     * 「這個產業永遠不適用」與「還沒抓到」不得合併成同一個成因。
     */
    #[Test]
    public function quality_is_not_applicable_for_industries_the_existing_policy_already_excludes(): void
    {
        $block = $this->block(HealthBlock::Quality, [
            'industryBucket' => 'not_applicable',
            'metrics' => new OrderInventoryMetrics(
                dsoChangeDays: $this->threshold('quality.dso_change_days_better') - 5.0,
                ocfToNetIncome: $this->threshold('quality.ocf_to_net_income_strong') + 0.5,
            ),
            'financialPeriod' => '2026-Q2',
        ]);

        $this->assertNull($block->verdict);
        $this->assertSame(HealthUnavailableReason::NotApplicable, $block->unavailableReason);
        $this->assertSame('2026-Q2', $block->asOf);
    }

    /** 序列在但兩項都算不出來：Indeterminate，與序列不存在的 NotYet 分得開。 */
    #[Test]
    public function quality_is_indeterminate_when_the_series_exists_but_neither_signal_can_be_computed(): void
    {
        $block = $this->block(HealthBlock::Quality, [
            'metrics' => new OrderInventoryMetrics(dsoChangeDays: null, ocfToNetIncome: null),
        ]);

        $this->assertNull($block->verdict);
        $this->assertSame(HealthUnavailableReason::Indeterminate, $block->unavailableReason);
    }

    /** 只有一項算得出來時照樣判定，理由寫明只依哪一項。 */
    #[Test]
    public function quality_still_decides_on_a_single_signal_and_says_which_one(): void
    {
        $block = $this->block(HealthBlock::Quality, [
            'metrics' => new OrderInventoryMetrics(
                dsoChangeDays: null,
                ocfToNetIncome: $this->threshold('quality.ocf_to_net_income_strong') + 0.5,
            ),
        ]);

        $this->assertSame(HealthVerdict::Positive, $block->verdict);
        $this->assertReasonContains($block, '僅依營業現金流');
    }

    /** 兩項反向 → Neutral，與估值的衝突處理一致。不取平均、不挑一個。 */
    #[Test]
    public function quality_falls_back_to_neutral_when_the_two_signals_point_in_opposite_directions(): void
    {
        $block = $this->block(HealthBlock::Quality, [
            'metrics' => new OrderInventoryMetrics(
                // 應收天數大增（負面）對上現金轉換良好（正面）。
                dsoChangeDays: $this->threshold('quality.dso_change_days_worse') + 10.0,
                ocfToNetIncome: $this->threshold('quality.ocf_to_net_income_strong') + 0.5,
            ),
        ]);

        $this->assertSame(HealthVerdict::Neutral, $block->verdict);
    }

    /**
     * 邊界含等於，兩項各自測。
     *
     * 天數的方向與其他指標相反（變多＝較差），所以 better 那端用 `<=`、
     * worse 那端用 `>=`；把任一端收成嚴格不等這條就紅。
     */
    #[Test]
    public function quality_thresholds_include_their_own_boundary(): void
    {
        $atOcfStrong = $this->block(HealthBlock::Quality, [
            'metrics' => new OrderInventoryMetrics(
                dsoChangeDays: null,
                ocfToNetIncome: $this->threshold('quality.ocf_to_net_income_strong'),
            ),
        ]);
        $atOcfWeak = $this->block(HealthBlock::Quality, [
            'metrics' => new OrderInventoryMetrics(
                dsoChangeDays: null,
                ocfToNetIncome: $this->threshold('quality.ocf_to_net_income_weak'),
            ),
        ]);
        $atDsoBetter = $this->block(HealthBlock::Quality, [
            'metrics' => new OrderInventoryMetrics(
                dsoChangeDays: $this->threshold('quality.dso_change_days_better'),
                ocfToNetIncome: null,
            ),
        ]);
        $atDsoWorse = $this->block(HealthBlock::Quality, [
            'metrics' => new OrderInventoryMetrics(
                dsoChangeDays: $this->threshold('quality.dso_change_days_worse'),
                ocfToNetIncome: null,
            ),
        ]);

        $this->assertSame(HealthVerdict::Positive, $atOcfStrong->verdict);
        $this->assertSame(HealthVerdict::Neutral, $atOcfWeak->verdict);
        $this->assertSame(HealthVerdict::Positive, $atDsoBetter->verdict);
        $this->assertSame(HealthVerdict::Negative, $atDsoWorse->verdict);
    }

    // ---------- 整份判讀 ----------

    /**
     * 四塊永遠都在，順序固定。
     *
     * 不可評估的塊被拿掉的話，使用者只會看到一份比較短的清單，
     * 而不知道少了什麼、為什麼少——這裡的快照什麼都沒有，四塊全不可評估。
     */
    #[Test]
    public function all_four_blocks_are_present_even_when_none_can_be_evaluated(): void
    {
        $read = (new LongTermHealthReader)->read($this->snapshot());

        $this->assertSame(
            [HealthBlock::Valuation, HealthBlock::ReturnOnEquity, HealthBlock::Growth, HealthBlock::Quality],
            array_map(fn (HealthBlockResult $b): HealthBlock => $b->block, $read->blocks),
        );

        foreach ($read->blocks as $block) {
            $this->assertNull($block->verdict);
            $this->assertNotNull($block->unavailableReason);
        }
    }

    /** 公式版本取自 config，不寫死——門檻改過之後舊判讀要能被分辨出是哪一版算的。 */
    #[Test]
    public function the_formula_version_comes_from_config(): void
    {
        config(['health.formula_version' => '9999-12-31.7']);

        $this->assertSame('9999-12-31.7', (new LongTermHealthReader)->read($this->snapshot())->formulaVersion);
    }

    /**
     * 零 IO：這個類別不得注入任何 service。
     *
     * 一旦能注入，判讀就可能不再只由快照決定，而「同一份快照必產出相同判讀」
     * 是整個 snapshot 設計存在的理由。
     */
    #[Test]
    public function the_reader_takes_no_collaborators(): void
    {
        $constructor = (new ReflectionClass(LongTermHealthReader::class))->getConstructor();

        $this->assertTrue(
            $constructor === null || $constructor->getNumberOfParameters() === 0,
            'LongTermHealthReader 必須零 IO，不得注入任何 service。',
        );
    }

    // ---------- helpers ----------

    private function threshold(string $key): float
    {
        $value = config("health.{$key}");
        $this->assertTrue(is_numeric($value), "health.{$key} 必須是數值門檻。");

        return (float) $value;
    }

    /** @param array<string, mixed> $overrides */
    private function snapshot(array $overrides = []): HealthInputSnapshot
    {
        return new HealthInputSnapshot(...array_merge([
            'symbol' => '2330.TW',
            'market' => 'TW',
            'bars' => 80,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function block(HealthBlock $wanted, array $overrides = []): HealthBlockResult
    {
        foreach ((new LongTermHealthReader)->read($this->snapshot($overrides))->blocks as $block) {
            if ($block->block === $wanted) {
                return $block;
            }
        }

        $this->fail("blocks 裡缺少 {$wanted->value}。");
    }

    private function assertReasonContains(HealthBlockResult $block, string $needle): void
    {
        $joined = implode(' | ', $block->reasons);

        $this->assertStringContainsString($needle, $joined);
    }

    /**
     * 仿 FundamentalsService::valuationPercentiles() 的實際形狀
     * （`array<string, array{value, percentile, min, median, max, samples}>`）。
     * 樣本不足的那一項在真實輸出裡是整個鍵不存在，不是 null。
     *
     * @return array<string, array<string, float|int>>|null
     */
    private function percentiles(?float $per = null, ?float $pbr = null): ?array
    {
        $out = [];

        foreach (['per' => $per, 'pbr' => $pbr] as $metric => $percentile) {
            if ($percentile === null) {
                continue;
            }

            $out[$metric] = [
                'value' => 20.0,
                'percentile' => $percentile,
                'min' => 8.0,
                'median' => 18.0,
                'max' => 40.0,
                'samples' => 24,
            ];
        }

        return $out === [] ? null : $out;
    }
}
