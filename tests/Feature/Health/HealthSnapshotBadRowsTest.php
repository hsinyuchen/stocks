<?php

namespace Tests\Feature\Health;

use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Services\Health\HealthSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 健檢的 cachedFor() 為了「零上游請求」直接讀 daily_prices，繞過 CachedMarketDataProvider
 * 的讀出口——所以它得自己做同樣兩件事：SQL 過掉死列、讀出後過 OhlcRepair。
 */
class HealthSnapshotBadRowsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 健檢直接讀 model、不經快取層：同樣的壞列在那裡也要修，否則個股頁的
     * healthPayload() 沒攔、整頁 500——這是圖表修好後仍會炸的漏網之魚。
     */
    public function test_health_snapshot_survives_bad_rows_in_the_table(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '6546.TWO']);
        $start = CarbonImmutable::parse('2026-06-01');
        for ($i = 0; $i < 30; $i++) {
            DailyPrice::query()->create([
                'instrument_id' => $instrument->id, 'priced_at' => $start->addDays($i)->toDateString(),
                'open' => $i === 0 ? 81.19 : 82.0, 'high' => 82.9, 'low' => 81.3, 'close' => 81.9, 'volume' => 1000,
            ]);
        }
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id, 'priced_at' => '2026-07-01',
            'open' => 81.9, 'high' => 0.0, 'low' => 0.0, 'close' => 0.0, 'volume' => 0,
        ]);

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertSame(30, $snapshot->bars);
        $this->assertNotEmpty($snapshot->indicators);
    }
}
