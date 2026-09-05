<?php

namespace Tests\Unit;

use App\Data\DailyPriceData;
use App\Support\CompletedBars;
use PHPUnit\Framework\TestCase;

class CompletedBarsTest extends TestCase
{
    private function bar(string $date, bool $partial = false): DailyPriceData
    {
        return new DailyPriceData('2330.TW', $date, 100.0, 101.0, 99.0, 100.0, 1000, $partial);
    }

    public function test_trailing_partial_bar_is_dropped(): void
    {
        $bars = [$this->bar('2026-09-01'), $this->bar('2026-09-02', partial: true)];

        $out = CompletedBars::only($bars);

        $this->assertCount(1, $out);
        $this->assertSame('2026-09-01', $out[0]->date);
    }

    public function test_series_without_partial_bars_is_returned_as_is(): void
    {
        $bars = [$this->bar('2026-09-01'), $this->bar('2026-09-02')];

        $this->assertSame($bars, CompletedBars::only($bars));
    }

    public function test_empty_series_stays_empty(): void
    {
        $this->assertSame([], CompletedBars::only([]));
    }

    public function test_all_partial_becomes_empty(): void
    {
        $this->assertSame([], CompletedBars::only([$this->bar('2026-09-02', partial: true)]));
    }
}
