<?php

namespace Tests\Unit;

use App\Data\FundamentalsData;
use App\Data\HealthBlockResult;
use App\Data\HealthInputSnapshot;
use App\Data\LongTermRead;
use App\Data\OrderInventoryMetrics;
use App\Data\ShortTermRead;
use App\Enums\AssetType;
use App\Enums\HealthBlock;
use App\Enums\HealthUnavailableReason;
use App\Enums\HealthVerdict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HealthDataTest extends TestCase
{
    /**
     * 三態俱全。Neutral 不可省——「沒達到正面門檻」不等於「負面證據」，
     * 把前者講成後者是本框架五個階段一路在防的失效模式。
     */
    #[Test]
    public function a_verdict_has_a_neutral_case(): void
    {
        $this->assertSame('positive', HealthVerdict::Positive->value);
        $this->assertSame('neutral', HealthVerdict::Neutral->value);
        $this->assertSame('negative', HealthVerdict::Negative->value);

        // 三個值互不相同：把 Neutral 併到 Negative 上（同值）與刪掉它一樣，
        // 都會讓「還不夠好」與「不好」在序列化後長成同一格。
        $this->assertCount(3, array_unique(array_column(HealthVerdict::cases(), 'value')));
    }

    /**
     * 可評估與不可評估互斥。同時有 verdict 與 reason 的列會讓呈現層
     * 不知道該顯示哪一個，而兩種顯示對使用者是相反的意思。
     */
    #[Test]
    public function an_evaluable_block_carries_no_unavailable_reason(): void
    {
        $block = HealthBlockResult::evaluated(
            HealthBlock::Growth,
            HealthVerdict::Positive,
            ['月營收年增 +23%'],
            '2026-06-30',
        );

        $this->assertSame(HealthVerdict::Positive, $block->verdict);
        $this->assertNull($block->unavailableReason);
        $this->assertSame('2026-06-30', $block->asOf);
    }

    #[Test]
    public function an_unavailable_block_carries_a_reason_and_no_verdict(): void
    {
        $block = HealthBlockResult::unavailable(
            HealthBlock::Valuation,
            HealthUnavailableReason::NotYet,
        );

        $this->assertNull($block->verdict);
        $this->assertSame(HealthUnavailableReason::NotYet, $block->unavailableReason);
    }

    /**
     * 序列化後三種狀態仍分得開：可評估、不可評估、以及「中性」——
     * 前端要用它們切三個不同的樣式，壓成布林就分不出來。
     */
    #[Test]
    public function the_three_states_serialise_distinctly(): void
    {
        $positive = HealthBlockResult::evaluated(HealthBlock::ReturnOnEquity, HealthVerdict::Positive, [], null)->toArray();
        $neutral = HealthBlockResult::evaluated(HealthBlock::ReturnOnEquity, HealthVerdict::Neutral, [], null)->toArray();
        $missing = HealthBlockResult::unavailable(HealthBlock::ReturnOnEquity, HealthUnavailableReason::NotInUniverse)->toArray();

        $this->assertSame('positive', $positive['verdict']);
        $this->assertSame('neutral', $neutral['verdict']);
        $this->assertNull($missing['verdict']);
        $this->assertSame('not_in_universe', $missing['unavailable_reason']);
        $this->assertNull($positive['unavailable_reason']);
    }

    /**
     * 快照帶得動每一項的資料日。實測目前資料庫已有時間錯位（價格到 08-25、
     * 籌碼到 08-17、fundamentals 到 08-05），沒有逐項日期，使用者看到的
     * 是一份混了三個日期的判讀。
     */
    #[Test]
    public function the_snapshot_carries_a_date_for_every_input(): void
    {
        $snapshot = new HealthInputSnapshot(
            symbol: '2330.TW',
            market: 'tw',
            bars: 80,
            priceAsOf: '2026-08-25',
            chipAsOf: '2026-08-17',
            fundamentalsAsOf: '2026-08-05',
            financialPeriod: '2026Q2',
            cachedOnly: true,
            assetType: AssetType::Stock,
        );

        $array = $snapshot->toArray();

        $this->assertSame('2026-08-25', $array['price_as_of']);
        $this->assertSame('2026-08-17', $array['chip_as_of']);
        $this->assertSame('2026-08-05', $array['fundamentals_as_of']);
        $this->assertSame(80, $array['bars']);
        $this->assertTrue($array['cached_only']);
        $this->assertSame('stock', $array['asset_type']);

        // 三個日期各自成鍵。少掉任何一個，呈現層就只能拿另一個日期去講這一項，
        // 而實測三者相差三週。
        $this->assertArrayHasKey('price_as_of', $array);
        $this->assertArrayHasKey('chip_as_of', $array);
        $this->assertArrayHasKey('fundamentals_as_of', $array);
    }

    /**
     * 快照帶的是資料本身，不只是 metadata。
     *
     * 只帶日期的話，「同一份快照必產出相同判讀」這個不變式無從驗證——
     * 兩個消費端拿著同樣的 metadata 仍可能各自去取到不同的資料。
     * 這條斷言釘住 reader 需要的每一項輸入都在快照上。
     */
    #[Test]
    public function the_snapshot_carries_the_inputs_not_just_their_dates(): void
    {
        $snapshot = new HealthInputSnapshot(
            symbol: '2330.TW',
            market: 'tw',
            bars: 80,
            indicators: ['k' => 62.0, 'rsi14' => 58.0],
            industryBucket: 'suited',
        );

        $this->assertSame(62.0, $snapshot->indicators['k']);
        $this->assertSame('suited', $snapshot->industryBucket);
        $this->assertSame([], $snapshot->chipFlows, '沒有籌碼時是空陣列，不是 null——空陣列代表「查過、沒有」');
        $this->assertNull($snapshot->metrics, '沒有財報序列時是 null——代表「沒有這份資料」');

        // 有財報序列時原樣帶著。中長線四塊全部從這兩個物件算出來，
        // 快照只留日期的話 reader 就得自己去 IO，純計算的保證隨即失效。
        $withFinancials = new HealthInputSnapshot(
            symbol: '2330.TW',
            market: 'tw',
            bars: 80,
            fundamentals: new FundamentalsData(roe: 20.5),
            metrics: new OrderInventoryMetrics(ocfToNetIncome: 1.2, revenueYoy: 0.23),
            valuationPercentiles: ['per' => ['value' => 18.0, 'percentile' => 24.0, 'min' => 10.0, 'median' => 20.0, 'max' => 30.0, 'samples' => 25]],
        );

        $this->assertSame(20.5, $withFinancials->fundamentals?->roe);
        $this->assertSame(1.2, $withFinancials->metrics?->ocfToNetIncome);
        $this->assertSame(0.23, $withFinancials->metrics?->revenueYoy);
        $this->assertSame(24.0, $withFinancials->valuationPercentiles['per']['percentile']);
    }

    /**
     * 背離是獨立欄位，不是「兩個立場相加為 0」。
     * SignalEngine 刻意把籌碼排除在 score 之外、另外輸出 alignment，
     * 正是因為背離比同向更有資訊量；壓成一個數字會把它抹掉。
     *
     * **而且是三態不是布林。** SignalEngine::alignment() 的 `none` 是「無法判定」，
     * 壓成 `bool $diverging` 會讓 `confirm` 與 `none` 併成同一格，於是一檔連一列
     * 籌碼都沒有的美股會得到「是否背離：否」這個肯定的否定答案。
     */
    #[Test]
    public function divergence_is_its_own_three_state_field(): void
    {
        $read = new ShortTermRead(
            technicalStance: 'bullish',
            chipStance: 'distributing',
            alignment: 'diverge',
            technicalReasons: ['KD 黃金交叉'],
            chipReasons: ['外資近五日淨賣超'],
        );

        $array = $read->toArray();

        $this->assertSame('diverge', $array['alignment']);
        $this->assertSame('bullish', $array['technical_stance']);
        $this->assertSame('distributing', $array['chip_stance']);

        // 兩邊都中性不是背離，是「兩邊都沒訊號」——而那在 SignalEngine 裡是
        // `none`，不是 `confirm`。若三態被壓成布林，這一格會與下一格同值。
        $bothNeutral = new ShortTermRead(
            technicalStance: 'neutral',
            chipStance: 'neutral',
            alignment: null,
        );

        $this->assertNull($bothNeutral->toArray()['alignment']);

        // 同向確認：與「無法判定」必須是兩個不同的值。
        $confirm = new ShortTermRead(
            technicalStance: 'bullish',
            chipStance: 'accumulating',
            alignment: 'confirm',
        );

        $this->assertSame('confirm', $confirm->toArray()['alignment']);
        $this->assertNotSame($bothNeutral->toArray()['alignment'], $confirm->toArray()['alignment']);

        // 沒有籌碼資料（美股）不是「不背離」，是無從判定。
        $noChip = new ShortTermRead(
            technicalStance: null,
            chipStance: null,
            alignment: null,
        );

        $this->assertNull($noChip->toArray()['alignment']);
    }

    /**
     * **兩個立場的理由要跟著序列化出去。**
     *
     * 全鏈路原本零斷言：`grep -rn "technical_reasons|chip_reasons" tests/` 只命中
     * 建構參數，之後沒有任何斷言讀它；面板與 prompt 的既有測試又都拿
     * `read($snapshot)->toArray()` 當期望值——**與被測值同一份程式碼，欄位一起
     * 消失一起綠**。這裡用字面值，刪掉 toArray() 的兩個鍵就會紅。
     *
     * 只給立場不給理由，使用者無從判斷可信度，等於要求他無條件相信一組未經回測
     * 的門檻。
     */
    #[Test]
    public function the_reasons_behind_each_stance_survive_serialisation(): void
    {
        $array = (new ShortTermRead(
            technicalStance: 'bullish',
            chipStance: 'accumulating',
            alignment: 'confirm',
            technicalReasons: ['KD 偏多，K 高於 D 7.5 點。', 'MACD 柱狀體明確為正，動能偏多。'],
            chipReasons: ['近 5 日外資合計買超 1,234 張。'],
        ))->toArray();

        $this->assertSame(
            ['KD 偏多，K 高於 D 7.5 點。', 'MACD 柱狀體明確為正，動能偏多。'],
            $array['technical_reasons'],
        );
        $this->assertSame(['近 5 日外資合計買超 1,234 張。'], $array['chip_reasons']);
    }

    #[Test]
    public function a_long_term_read_keeps_every_block_even_when_unavailable(): void
    {
        $read = new LongTermRead(
            blocks: [
                HealthBlockResult::unavailable(HealthBlock::Valuation, HealthUnavailableReason::NotYet),
                HealthBlockResult::unavailable(HealthBlock::ReturnOnEquity, HealthUnavailableReason::NotInUniverse),
                HealthBlockResult::unavailable(HealthBlock::Growth, HealthUnavailableReason::NotYet),
                HealthBlockResult::unavailable(HealthBlock::Quality, HealthUnavailableReason::NotApplicable),
            ],
            formulaVersion: '2026-08-26.1',
        );

        $this->assertCount(4, $read->toArray()['blocks'], '不可評估的塊也要留在輸出裡，讓使用者看得出為什麼');
    }
}
