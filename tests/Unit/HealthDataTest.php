<?php

namespace Tests\Unit;

use App\Data\HealthBlockResult;
use App\Data\HealthInputSnapshot;
use App\Data\LongTermRead;
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
     * 序列化後四種狀態仍分得開：三態判定各一，加上「不可評估」。
     *
     * **驗的是序列化出去的字面值，不是 enum 宣告本身。** 這三個字串是與前端的
     * 線上契約——`Search.jsx` 的 `HealthVerdictBadge` 直接比對
     * `verdict === 'neutral'`／`'positive'`／`'negative'` 切四個分支，改動任何一個
     * 值都會讓對應的分支靜默落到兜底的「不可評估」，畫面上看起來一切正常。
     *
     * Neutral 不可省，也不可與 Negative 同值：「沒達到正面門檻」不等於
     * 「負面證據」，把前者講成後者是本框架五個階段一路在防的失效模式。
     */
    #[Test]
    public function the_four_states_serialise_distinctly(): void
    {
        $serialised = [];

        foreach (HealthVerdict::cases() as $verdict) {
            $serialised[] = HealthBlockResult::evaluated(
                HealthBlock::ReturnOnEquity,
                $verdict,
                [],
                null,
            )->toArray();
        }

        $missing = HealthBlockResult::unavailable(HealthBlock::ReturnOnEquity, HealthUnavailableReason::NotInUniverse)->toArray();

        $this->assertSame(
            ['positive', 'neutral', 'negative'],
            array_column($serialised, 'verdict'),
            '三態的字面值與順序是與前端的契約，改動會讓對應的徽章分支靜默失效。',
        );

        foreach ($serialised as $row) {
            $this->assertNull($row['unavailable_reason'], '可評估的塊不得同時帶成因。');
        }

        $this->assertNull($missing['verdict']);
        $this->assertSame('not_in_universe', $missing['unavailable_reason']);
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

    /**
     * 不可評估的塊也要留在輸出裡，**而且要帶著各自的成因**。
     *
     * 只數 `assertCount(4, ...)` 幾乎測不到東西：`toArray()` 是一個沒有 filter 的
     * `array_map`，進去四筆出來必然四筆。這裡改比對逐塊的 `block` 與
     * `unavailable_reason`——刪掉某一塊、把四塊的成因壓成同一個值、或漏掉
     * `unavailable_reason` 這個鍵，才會各自紅一次。
     *
     * 少了成因，使用者只會看到一份比較短的清單，而不知道少了什麼、為什麼少。
     */
    #[Test]
    public function a_long_term_read_keeps_every_block_and_its_reason(): void
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

        $blocks = $read->toArray()['blocks'];

        $this->assertSame(
            ['valuation', 'return_on_equity', 'growth', 'quality'],
            array_column($blocks, 'block'),
        );
        $this->assertSame(
            ['not_yet', 'not_in_universe', 'not_yet', 'not_applicable'],
            array_column($blocks, 'unavailable_reason'),
        );
        $this->assertSame('2026-08-26.1', $read->toArray()['formula_version']);
    }
}
