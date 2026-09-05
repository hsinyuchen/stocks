<?php

namespace Tests\Unit;

use App\Data\DailyPriceData;
use App\Support\OhlcRepair;
use PHPUnit\Framework\TestCase;

/**
 * 案例數值取自 FinMind `TaiwanStockPrice` 的實際回傳（2026-09-05 查詢），
 * 不是編的：正式機就是被這幾根炸掉的。
 */
class OhlcRepairTest extends TestCase
{
    private function bar(float $open, float $high, float $low, float $close, int $volume = 1000, string $date = '2021-05-06'): DailyPriceData
    {
        return new DailyPriceData('6546.TWO', $date, $open, $high, $low, $close, $volume);
    }

    /** 6546 2021-05-06：正式機 log 裡 index 0 那一根。 */
    public function test_open_below_low_is_clamped_to_low_and_range_is_untouched(): void
    {
        $repaired = OhlcRepair::repair($this->bar(81.19, 82.9, 81.3, 81.9));

        $this->assertNotNull($repaired);
        $this->assertSame(81.3, $repaired->open);
        $this->assertSame(82.9, $repaired->high, 'high 不得被撐開去遷就 open');
        $this->assertSame(81.3, $repaired->low, 'low 不得被撐開去遷就 open');
        $this->assertSame(81.9, $repaired->close);
    }

    /** 6546 2015-07-30：open 高過 high 1.62。 */
    public function test_open_above_high_is_clamped_to_high(): void
    {
        $repaired = OhlcRepair::repair($this->bar(27.62, 26.0, 24.41, 25.0));

        $this->assertSame(26.0, $repaired->open);
        $this->assertSame(26.0, $repaired->high);
        $this->assertSame(24.41, $repaired->low);
    }

    /** 6546 2015-07-28：上市第一天 open 為 0，其餘正常。 */
    public function test_zero_open_falls_back_to_close(): void
    {
        $repaired = OhlcRepair::repair($this->bar(0.0, 33.06, 24.93, 27.5));

        $this->assertNotNull($repaired);
        $this->assertSame(27.5, $repaired->open);
        $this->assertSame(24.93, $repaired->low, 'open 為 0 不得把 low 拉到 0');
    }

    /** 6546 2017-11-22：無成交日，high＝low＝close＝0、open 沿用前值。 */
    public function test_no_trade_day_is_dropped_entirely(): void
    {
        $this->assertNull(OhlcRepair::repair($this->bar(14.03, 0.0, 0.0, 0.0, 0)));
    }

    public function test_close_outside_range_widens_range_instead_of_clamping_close(): void
    {
        $repaired = OhlcRepair::repair($this->bar(100.0, 101.0, 99.0, 103.0));

        $this->assertSame(103.0, $repaired->close, 'close 永遠不夾');
        $this->assertSame(103.0, $repaired->high);
        $this->assertSame(99.0, $repaired->low);
    }

    public function test_swapped_high_low_is_corrected(): void
    {
        $repaired = OhlcRepair::repair($this->bar(100.0, 99.0, 101.0, 100.5));

        $this->assertSame(101.0, $repaired->high);
        $this->assertSame(99.0, $repaired->low);
    }

    public function test_negative_volume_becomes_zero(): void
    {
        $this->assertSame(0, OhlcRepair::repair($this->bar(100.0, 101.0, 99.0, 100.5, -5))->volume);
    }

    /** 正常的棒原物回傳，不重新配置——這是每次讀出都會跑的熱路徑。 */
    public function test_valid_bar_is_returned_as_the_same_instance(): void
    {
        $bar = $this->bar(100.0, 101.0, 99.0, 100.5);

        $this->assertSame($bar, OhlcRepair::repair($bar));
    }

    public function test_partial_flag_survives_repair(): void
    {
        $bar = new DailyPriceData('6546.TWO', '2026-09-02', 81.19, 82.9, 81.3, 81.9, 1000, partial: true);

        $this->assertTrue(OhlcRepair::repair($bar)->partial);
    }

    public function test_repair_all_drops_dead_bars_and_reindexes(): void
    {
        $bars = [
            $this->bar(100.0, 101.0, 99.0, 100.5, date: '2026-09-01'),
            $this->bar(14.03, 0.0, 0.0, 0.0, 0, date: '2026-09-02'),
            $this->bar(81.19, 82.9, 81.3, 81.9, date: '2026-09-03'),
        ];

        $out = OhlcRepair::repairAll($bars);

        $this->assertCount(2, $out);
        $this->assertSame([0, 1], array_keys($out), '必須重新編號成 list，否則後續 array_map 配索引會錯位');
        $this->assertSame('2026-09-01', $out[0]->date);
        $this->assertSame('2026-09-03', $out[1]->date);
        $this->assertSame(81.3, $out[1]->open);
    }
}
