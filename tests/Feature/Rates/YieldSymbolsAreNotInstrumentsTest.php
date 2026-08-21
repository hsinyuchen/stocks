<?php

namespace Tests\Feature\Rates;

use App\Models\Instrument;
use App\Services\Rates\YieldCurveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class YieldSymbolsAreNotInstrumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetching_the_curve_never_creates_instruments(): void
    {
        Cache::flush();

        $before = Instrument::query()->count();

        app(YieldCurveService::class)->curve();

        // 殖利率不可交易。若這裡增加了 Instrument，代表某處又走回
        // CachedMarketDataProvider::dailyPrices()，會污染搜尋與自選股。
        $this->assertSame($before, Instrument::query()->count());

        foreach (['^TNX', '^IRX', '^FVX', '^TYX'] as $symbol) {
            $this->assertFalse(
                Instrument::query()->where('symbol', $symbol)->exists(),
                "殖利率代號 {$symbol} 不應存在於 instruments 表",
            );
        }
    }
}
