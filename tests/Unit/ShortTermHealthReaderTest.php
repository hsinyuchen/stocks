<?php

namespace Tests\Unit;

use App\Data\ChipFlowData;
use App\Data\HealthInputSnapshot;
use App\Enums\HealthUnavailableReason;
use App\Services\Health\LongTermHealthReader;
use App\Services\Health\ShortTermHealthReader;
use App\Services\SignalEngine;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * 短線判讀：技術與籌碼兩個維度，外加背離旗標。**沒有總分。**
 *
 * 與 {@see LongTermHealthReader} 同一模式——純計算、零 IO，
 * 輸入全部來自快照，因此每個分支都能用注入的假輸入精確測到。
 */
class ShortTermHealthReaderTest extends TestCase
{
    /**
     * 零 IO 的結構保證：建構子只能收 SignalEngine（它本身也是純計算）。
     *
     * 用反射釘住而不是靠人眼看：注入一個會查資料庫的服務不會讓任何一條行為測試
     * 變紅，只會讓「同一份快照必產出相同判讀」這個不變式悄悄失效。
     */
    #[Test]
    public function the_reader_only_depends_on_the_pure_signal_engine(): void
    {
        $parameters = (new ReflectionClass(ShortTermHealthReader::class))->getConstructor()?->getParameters() ?? [];

        $types = array_map(
            fn ($parameter): ?string => $parameter->getType() instanceof ReflectionNamedType
                ? $parameter->getType()->getName()
                : null,
            $parameters,
        );

        $this->assertSame([SignalEngine::class], $types);
    }

    /** 快照決定輸出：同一份輸入呼叫兩次，結果必須逐欄相同。 */
    #[Test]
    public function the_same_snapshot_always_yields_the_same_read(): void
    {
        $reader = new ShortTermHealthReader(new SignalEngine);
        $snapshot = $this->snapshot($this->bullishIndicators(), $this->flows([9_000_000]));

        $this->assertEquals($reader->read($snapshot)->toArray(), $reader->read($snapshot)->toArray());
    }

    #[Test]
    public function a_bullish_snapshot_with_heavy_buying_confirms(): void
    {
        $read = (new ShortTermHealthReader(new SignalEngine))->read(
            $this->snapshot($this->bullishIndicators(), $this->flows([9_000_000])),
        );

        $this->assertSame('bullish', $read->technicalStance);
        $this->assertSame('accumulating', $read->chipStance);
        $this->assertSame('confirm', $read->alignment);
    }

    /** 背離：技術偏多但法人在賣。這是兩個維度分開輸出的全部理由。 */
    #[Test]
    public function a_bullish_technical_read_against_heavy_selling_is_diverging(): void
    {
        $read = (new ShortTermHealthReader(new SignalEngine))->read(
            $this->snapshot($this->bullishIndicators(), $this->flows([-9_000_000])),
        );

        $this->assertSame('bullish', $read->technicalStance);
        $this->assertSame('distributing', $read->chipStance);
        $this->assertSame('diverge', $read->alignment);
    }

    /**
     * **不知道不是「不背離」。**
     *
     * SignalEngine::alignment() 的第三態 `none` 是「無法判定」（SignalFieldGuide
     * 自己這樣定義）。把它壓成 `false` 會與 `confirm` 併成同一格——於是一檔連一列
     * 籌碼都沒有的美股會得到「是否背離：否」，而那是對著沒有資料的一邊給出肯定的
     * 否定答案。**全部美股與所有尚無籌碼快取的台股都走這一支。**
     */
    #[Test]
    public function an_unavailable_stance_makes_the_alignment_unknown_not_confirmed(): void
    {
        $reader = new ShortTermHealthReader(new SignalEngine);

        // 籌碼不可評估（美股沒有三大法人資料）。
        $withoutChip = $reader->read($this->snapshot($this->bullishIndicators(), []));

        $this->assertSame('bullish', $withoutChip->technicalStance);
        $this->assertNull($withoutChip->chipStance);
        $this->assertNull($withoutChip->alignment);

        // 技術面不可評估（K 棒不足、指標仍在暖身期）。
        $withoutTechnical = $reader->read($this->snapshot([], $this->flows([-9_000_000])));

        $this->assertNull($withoutTechnical->technicalStance);
        $this->assertSame('distributing', $withoutTechnical->chipStance);
        $this->assertNull($withoutTechnical->alignment);

        // 對照組：三態必須真的是三個不同的值，否則上面兩條殺不死「恆回 null」。
        $confirming = $reader->read($this->snapshot($this->bullishIndicators(), $this->flows([9_000_000])));

        $this->assertSame('confirm', $confirming->alignment);
        $this->assertNotSame($confirming->alignment, $withoutChip->alignment);
    }

    /** 技術面資料不足是 null（不可評估），不是 'insufficient_data' 這個字串。 */
    #[Test]
    public function insufficient_technical_data_is_reported_as_unavailable(): void
    {
        $read = (new ShortTermHealthReader(new SignalEngine))->read($this->snapshot([], []));

        $this->assertNull($read->technicalStance);
        $this->assertNull($read->chipStance);
        $this->assertNull($read->rsi);
    }

    /** 中性帶要沿用到這裡：淨買 1 股不得在體質判讀裡變成「法人買超」。 */
    #[Test]
    public function a_single_share_of_net_buying_is_neutral_here_too(): void
    {
        $read = (new ShortTermHealthReader(new SignalEngine))->read(
            $this->snapshot($this->bullishIndicators(), $this->flows([1])),
        );

        $this->assertSame('neutral', $read->chipStance);
        $this->assertNull($read->alignment);
    }

    /** rsi 與 volume_ratio 是脈絡欄位，照抄快照、不參與判定。 */
    #[Test]
    public function context_fields_come_straight_from_the_snapshot(): void
    {
        $indicators = $this->bullishIndicators();
        $indicators['rsi'] = 62.5;
        $indicators['volume'] = 2_000_000;
        $indicators['volume_ma20'] = 1_000_000.0;

        $read = (new ShortTermHealthReader(new SignalEngine))->read($this->snapshot($indicators, []));

        $this->assertSame(62.5, $read->rsi);
        $this->assertSame(2.0, $read->volumeRatio);
    }

    /** 逐項 as_of 要照快照帶出去，呈現層才說得出每個維度各自停在哪一天。 */
    #[Test]
    public function each_dimension_carries_its_own_as_of(): void
    {
        $read = (new ShortTermHealthReader(new SignalEngine))->read(new HealthInputSnapshot(
            symbol: '2330.TW',
            market: 'tw',
            bars: 80,
            indicators: $this->bullishIndicators(),
            chipFlows: $this->flows([9_000_000]),
            priceAsOf: '2026-08-25',
            chipAsOf: '2026-08-17',
        ));

        $this->assertSame('2026-08-25', $read->priceAsOf);
        $this->assertSame('2026-08-17', $read->chipAsOf);
    }

    // ------------------------------------------------------------------
    // 新鮮度 gate
    // ------------------------------------------------------------------

    /**
     * **價格過舊時技術立場一律作廢**，即使 SignalEngine 算得出一個明確的立場。
     *
     * 實測超過 8 個交易日的技術立場與隨機猜無法區分（依據見 config/health.php
     * 的 technical 區塊）。那不是「證據變弱」而是**沒有證據**——照樣輸出一個
     * bullish 再標個日期讓使用者自己判斷，等於把純雜訊包裝成「舊但可參考」。
     */
    #[Test]
    public function a_stale_price_makes_the_technical_stance_unavailable(): void
    {
        $reader = new ShortTermHealthReader(new SignalEngine);

        $fresh = $reader->read($this->snapshot($this->bullishIndicators(), $this->flows([9_000_000])));
        $stale = $reader->read($this->snapshot(
            $this->bullishIndicators(),
            $this->flows([9_000_000]),
            priceStale: true,
        ));

        // 對照組：同樣一份指標在不過期時是算得出立場的，否則本條殺不死「恆回 null」。
        $this->assertSame('bullish', $fresh->technicalStance);

        $this->assertNull($stale->technicalStance);
        $this->assertSame(HealthUnavailableReason::Stale, $stale->technicalUnavailableReason);
    }

    /**
     * **兩個成因分得開。** 加了 gate 之後 `technicalStance` 為 null 有兩個來源，
     * 而它們對使用者是**不同的行動**：K 棒不足等分析跑過就有，價格過舊要等價格更新。
     * 只給一個 null 的話，呈現層無從區分，只能講一句對其中一半的人是錯的話。
     */
    #[Test]
    public function the_two_causes_of_an_unavailable_technical_stance_are_distinguishable(): void
    {
        $reader = new ShortTermHealthReader(new SignalEngine);

        // K 棒不足（指標仍在暖身期），價格本身不算舊。
        $notYet = $reader->read($this->snapshot([], []));

        $this->assertNull($notYet->technicalStance);
        $this->assertSame(HealthUnavailableReason::NotYet, $notYet->technicalUnavailableReason);

        // K 棒夠但價格過舊。
        $stale = $reader->read($this->snapshot($this->bullishIndicators(), [], priceStale: true));

        $this->assertNull($stale->technicalStance);
        $this->assertSame(HealthUnavailableReason::Stale, $stale->technicalUnavailableReason);

        // 兩者必須真的是兩個不同的值，否則上面兩條殺不死「恆回同一個成因」。
        $this->assertNotSame($notYet->technicalUnavailableReason, $stale->technicalUnavailableReason);

        // 算得出來時沒有成因可言。
        $this->assertNull($reader->read($this->snapshot($this->bullishIndicators(), []))->technicalUnavailableReason);
    }

    /**
     * **兩者同時成立時報 `Stale`。** gate 優先於 `insufficient_data`。
     *
     * 那是使用者能採取行動的那一個：K 棒不足有很大一部分正是因為價格根本沒在更新，
     * 回 `NotYet`＝「等分析或掃描再跑幾次就會有」會把人指向一個不會解決問題的動作。
     */
    #[Test]
    public function the_gate_wins_over_insufficient_data(): void
    {
        $read = (new ShortTermHealthReader(new SignalEngine))->read(
            $this->snapshot([], [], priceStale: true),
        );

        $this->assertNull($read->technicalStance);
        $this->assertSame(HealthUnavailableReason::Stale, $read->technicalUnavailableReason);
    }

    /**
     * 立場作廢時**理由與背離狀態一併作廢**。
     *
     * 理由講的是被丟掉的那個立場為什麼成立，背離則是拿那個立場算出來的——留著
     * 等於用一個剛宣告不可評估的結論去斷言「技術與籌碼一致」，而引用紀律又要求
     * 模型在背離時兩者都講。
     */
    #[Test]
    public function a_gated_stance_takes_its_reasons_and_alignment_with_it(): void
    {
        $reader = new ShortTermHealthReader(new SignalEngine);
        $arguments = [$this->bullishIndicators(), $this->flows([9_000_000])];

        // 對照組：不過期時這兩樣都有值。
        $fresh = $reader->read($this->snapshot(...$arguments));

        $this->assertNotSame([], $fresh->technicalReasons);
        $this->assertSame('confirm', $fresh->alignment);

        $stale = $reader->read($this->snapshot(...[...$arguments, 'priceStale' => true]));

        $this->assertSame([], $stale->technicalReasons);
        $this->assertNull($stale->alignment);
    }

    /**
     * **籌碼面不被 gate。** 技術面判成不可評估時，籌碼立場與理由照樣輸出。
     *
     * 籌碼立場的持續性沒有量過；技術面的門檻有實測依據，籌碼面沒有，套一個沒有
     * 量測依據的門檻違反本專案的紀律。代價是畫面上會並列「技術面：資料過舊」
     * 與「籌碼面：買超」，而後者基於同樣舊的資料——所以年齡照樣輸出（見下一條）。
     */
    #[Test]
    public function the_chip_stance_survives_the_technical_gate(): void
    {
        $read = (new ShortTermHealthReader(new SignalEngine))->read($this->snapshot(
            $this->bullishIndicators(),
            $this->flows([9_000_000]),
            priceStale: true,
        ));

        $this->assertNull($read->technicalStance);
        $this->assertSame('accumulating', $read->chipStance);
        $this->assertNotSame([], $read->chipReasons);
    }

    /** 兩個年齡照抄快照帶出去，呈現層才說得出「幾個交易日前」而不是只印一個日期。 */
    #[Test]
    public function both_ages_are_carried_through_to_the_read(): void
    {
        $read = (new ShortTermHealthReader(new SignalEngine))->read(new HealthInputSnapshot(
            symbol: '2330.TW',
            market: 'tw',
            bars: 80,
            indicators: $this->bullishIndicators(),
            chipFlows: $this->flows([9_000_000]),
            priceAsOf: '2026-08-25',
            chipAsOf: '2026-08-17',
            priceAgeTradingDays: 2,
            chipAgeTradingDays: 8,
        ));

        $this->assertSame(2, $read->priceAgeTradingDays);
        $this->assertSame(8, $read->chipAgeTradingDays);

        // 序列化也要帶著：呈現層讀的是 toArray()，不是物件。
        $array = $read->toArray();

        $this->assertSame(2, $array['price_age_trading_days']);
        $this->assertSame(8, $array['chip_age_trading_days']);
        $this->assertNull($array['technical_unavailable_reason']);
    }

    // ---------- helpers ----------

    /**
     * @param  array<string, mixed>  $indicators
     * @param  list<ChipFlowData>  $chipFlows
     */
    private function snapshot(array $indicators, array $chipFlows, bool $priceStale = false): HealthInputSnapshot
    {
        return new HealthInputSnapshot(
            symbol: '2330.TW',
            market: 'tw',
            bars: 80,
            indicators: $indicators,
            chipFlows: $chipFlows,
            priceStale: $priceStale,
        );
    }

    /** @return array<string, float|int> */
    private function bullishIndicators(): array
    {
        return [
            'k' => 70.0, 'd' => 60.0, 'macd_histogram' => 1.5, 'ma5' => 110.0, 'ma20' => 100.0,
            'volume' => 1_000_000, 'volume_ma20' => 1_000_000.0,
        ];
    }

    /**
     * @param  list<int>  $foreignNets
     * @return list<ChipFlowData>
     */
    private function flows(array $foreignNets): array
    {
        $out = [];

        foreach ($foreignNets as $i => $net) {
            $out[] = new ChipFlowData(sprintf('2026-08-%02d', $i + 11), $net, 0, 0, $net);
        }

        return $out;
    }
}
