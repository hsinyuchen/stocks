<?php

namespace Tests\Unit;

use App\Data\ChipFlowData;
use App\Data\HealthInputSnapshot;
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
    public function a_bullish_snapshot_with_heavy_buying_is_not_diverging(): void
    {
        $read = (new ShortTermHealthReader(new SignalEngine))->read(
            $this->snapshot($this->bullishIndicators(), $this->flows([9_000_000])),
        );

        $this->assertSame('bullish', $read->technicalStance);
        $this->assertSame('accumulating', $read->chipStance);
        $this->assertFalse($read->diverging);
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
        $this->assertTrue($read->diverging);
    }

    /**
     * **不知道不算背離。**
     *
     * 任一維度不可評估時 diverging 必須是 false：把「其中一邊沒有資料」講成
     * 「兩邊互相矛盾」，是憑空造出一個資料沒有支持的宣稱。
     */
    #[Test]
    public function an_unavailable_stance_never_counts_as_divergence(): void
    {
        $reader = new ShortTermHealthReader(new SignalEngine);

        // 籌碼不可評估（美股沒有三大法人資料）。
        $withoutChip = $reader->read($this->snapshot($this->bullishIndicators(), []));

        $this->assertSame('bullish', $withoutChip->technicalStance);
        $this->assertNull($withoutChip->chipStance);
        $this->assertFalse($withoutChip->diverging);

        // 技術面不可評估（K 棒不足、指標仍在暖身期）。
        $withoutTechnical = $reader->read($this->snapshot([], $this->flows([-9_000_000])));

        $this->assertNull($withoutTechnical->technicalStance);
        $this->assertSame('distributing', $withoutTechnical->chipStance);
        $this->assertFalse($withoutTechnical->diverging);
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
        $this->assertFalse($read->diverging);
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

    // ---------- helpers ----------

    /**
     * @param  array<string, mixed>  $indicators
     * @param  list<ChipFlowData>  $chipFlows
     */
    private function snapshot(array $indicators, array $chipFlows): HealthInputSnapshot
    {
        return new HealthInputSnapshot(
            symbol: '2330.TW',
            market: 'tw',
            bars: 80,
            indicators: $indicators,
            chipFlows: $chipFlows,
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
