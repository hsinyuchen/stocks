<?php

namespace Tests\Feature\Rates;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Models\Instrument;
use App\Services\Market\CachedMarketDataProvider;
use App\Services\Rates\YieldCurveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class YieldSymbolsAreNotInstrumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetching_the_curve_never_creates_instruments(): void
    {
        Cache::flush();

        $before = Instrument::query()->count();

        // phpunit.xml 把 MARKET_DATA_DRIVER 鎖 fake，容器預設綁 FakeMarketDataProvider——
        // 它完全不碰 DB，若 YieldCurveService 的抓取鏈路「改回」吃容器綁定的
        // MarketDataProvider，這裡永遠測不出來（fake 不留痕跡）。
        //
        // 因此不論 driver 設什麼，這裡都直接把 MarketDataProvider 覆蓋綁成一個「真的」
        // CachedMarketDataProvider（包一個會記錄呼叫次數的 stub 上游）。一旦殖利率鏈路
        // 真的碰到容器綁定的 MarketDataProvider，dailyPrices() 就會實際跑
        // Instrument::createOrFirst() 並留下可斷言的證據；健康的實作（YieldCurveService
        // 只依賴 YieldCurveProvider）完全不會呼叫到它。不要為了「簡化」把這段改回直接
        // 呼叫 app(YieldCurveService::class)->curve() 而不覆蓋綁定——那樣會讓本測試
        // 在任何 driver 設定下都測不出這個迴歸。
        $spyUpstream = new class implements MarketDataProvider
        {
            public int $calls = 0;

            public function quote(string $symbol): MarketQuoteData
            {
                $this->calls++;

                throw new RuntimeException('YieldCurveService 的抓取鏈路不應呼叫容器綁定的 MarketDataProvider::quote()。');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                $this->calls++;

                return [
                    new DailyPriceData($symbol, '2026-08-18', 4.0, 4.1, 3.9, 4.0, 0),
                    new DailyPriceData($symbol, '2026-08-19', 4.0, 4.1, 3.9, 4.0, 0),
                ];
            }
        };

        $this->app->bind(MarketDataProvider::class, fn () => new CachedMarketDataProvider($spyUpstream));

        app(YieldCurveService::class)->curve();

        $this->assertSame(
            0,
            $spyUpstream->calls,
            'YieldCurveService 的抓取鏈路不應呼叫容器綁定的 MarketDataProvider。',
        );

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
