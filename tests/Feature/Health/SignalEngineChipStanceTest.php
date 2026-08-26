<?php

namespace Tests\Feature\Health;

use App\Contracts\ChipDataProvider;
use App\Data\ChipFlowData;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Analysis\SymbolContextService;
use App\Services\SignalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 籌碼立場的中性帶。
 *
 * 修的是一個既有缺陷：`SignalEngine::withChip()` 原本只看近五日 foreign_net 的
 * 正負，**外資淨買 1 股就判 accumulating**，而呈現層與 prompt 會把它講成
 * 「法人買超」。那是把雜訊宣稱成訊號。
 *
 * 這裡的門檻一律**從 config 取值推導**（`health.chip.neutral_band_volume_share`），
 * 不寫死 0.01——門檻調整時測試該跟著移動，而不是變成一組與判準無關的常數。
 */
class SignalEngineChipStanceTest extends TestCase
{
    use RefreshDatabase;

    /** 近 20 日均量；分母是「均量 × 採計天數」，見 SignalEngine::foreignVolumeShare()。 */
    private const VOLUME_MA20 = 1_000_000;

    #[Test]
    public function a_single_share_of_net_foreign_buying_is_not_accumulating(): void
    {
        $result = (new SignalEngine)->evaluate(
            $this->snapshotWithVolume(),
            $this->flows([1, 0, 0, 0, 0]),
        );

        $this->assertSame('neutral', $result['chip']['stance']);
        // 立場中性時不得宣稱同向或背離。
        $this->assertSame('none', $result['alignment']);
    }

    /**
     * 中性帶邊界：**恰好等於門檻不是中性**。
     *
     * 判準是 `abs(share) < band`，所以等於門檻的那一檔要判成買超；少一股才中性。
     * 兩側各測一次，`<` 與 `<=` 才分得開。
     */
    #[Test]
    public function the_neutral_band_boundary_is_exclusive_and_derived_from_config(): void
    {
        $exact = $this->sharesAtBand();

        $atBand = (new SignalEngine)->evaluate($this->snapshotWithVolume(), $this->flows([$exact, 0, 0, 0, 0]));
        $justInside = (new SignalEngine)->evaluate($this->snapshotWithVolume(), $this->flows([$exact - 1, 0, 0, 0, 0]));

        $this->assertSame('accumulating', $atBand['chip']['stance'], '恰好等於門檻不屬於中性帶');
        $this->assertSame('neutral', $justInside['chip']['stance'], '少一股就落回中性帶');
    }

    /** 賣超側同樣走絕對值，門檻對稱。 */
    #[Test]
    public function the_neutral_band_is_symmetric_for_selling(): void
    {
        $exact = $this->sharesAtBand();

        $atBand = (new SignalEngine)->evaluate($this->snapshotWithVolume(), $this->flows([-$exact, 0, 0, 0, 0]));
        $justInside = (new SignalEngine)->evaluate($this->snapshotWithVolume(), $this->flows([-($exact - 1), 0, 0, 0, 0]));

        $this->assertSame('distributing', $atBand['chip']['stance']);
        $this->assertSame('neutral', $justInside['chip']['stance']);
    }

    /**
     * 中性的理由必須說清楚是「量太小」而不是「買賣相抵」。
     *
     * 兩者對使用者是完全不同的事實：相抵代表買賣雙方都動過，量太小代表根本沒動。
     */
    #[Test]
    public function a_below_band_flow_explains_itself_as_too_small_not_as_offsetting(): void
    {
        $result = (new SignalEngine)->evaluate($this->snapshotWithVolume(), $this->flows([1, 0, 0, 0, 0]));

        $this->assertStringNotContainsString('相抵', $result['chip']['reasons'][0]);
        $this->assertStringContainsString('成交量', $result['chip']['reasons'][0]);
        $this->assertNotNull($result['chip']['foreign_volume_share']);
    }

    /** 超過門檻仍要照常判定，中性帶不能把真正的買超也吃掉。 */
    #[Test]
    public function a_meaningful_net_buy_is_still_accumulating(): void
    {
        $result = (new SignalEngine)->evaluate(
            $this->snapshotWithVolume(),
            $this->flows([$this->sharesAtBand() * 5, 0, 0, 0, 0]),
        );

        $this->assertSame('accumulating', $result['chip']['stance']);
    }

    // ---------- 至少兩個消費端 ----------

    /**
     * 消費端 1：`SymbolContextService`（個股分析與個股問答共用的脈絡來源）。
     *
     * 直接測 `SignalEngine` 只證明計算對了，證明不了那個結果有傳到下游——
     * 消費端拿到的 snapshot 若不帶成交量，中性帶就形同不存在。
     */
    #[Test]
    public function the_neutral_band_reaches_the_symbol_context_consumer(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '台積電', 'market' => 'TW', 'currency' => 'TWD']);

        $context = app(SymbolContextService::class)->forSymbol('2330.TW', $this->flows([1, 0, 0, 0, 0]));

        $this->assertSame('neutral', $context['rule_signal']['chip']['stance']);
    }

    /**
     * 消費端 2：`DashboardController` 的自選股異動（走 ChipDataService → DB → SignalEngine）。
     *
     * 與上一條刻意選不同的取得路徑：這裡的籌碼來自資料庫而非呼叫端傳入，
     * 兩條路都驗過，才排除得掉「只有其中一條接上了成交量」。
     */
    #[Test]
    public function the_neutral_band_reaches_the_dashboard_consumer(): void
    {
        $this->app->instance(ChipDataProvider::class, new class implements ChipDataProvider
        {
            public function fetch(string $symbol, int $days): array
            {
                return [
                    new ChipFlowData('2026-06-16', 1, 0, 0, 1),
                    new ChipFlowData('2026-06-17', 0, 0, 0, 0),
                    new ChipFlowData('2026-06-18', 0, 0, 0, 0),
                    new ChipFlowData('2026-06-19', 0, 0, 0, 0),
                    new ChipFlowData('2026-06-20', 0, 0, 0, 0),
                ];
            }
        });

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '台積電', 'market' => 'TW', 'currency' => 'TWD']);
        $watchlist = $user->watchlists()->create(['name' => '核心持股']);
        $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);

        $response = $this->actingAs($user)->getDashboard()->assertOk();

        $response->assertJsonPath('props.watchlistMovers.0.chip.stance', 'neutral');
    }

    // ---------- helpers ----------

    /**
     * 技術面偏多、且帶有近 20 日均量的快照。
     *
     * @return array<string, float|int>
     */
    private function snapshotWithVolume(): array
    {
        return [
            'k' => 70.0, 'd' => 60.0, 'macd_histogram' => 1.5, 'ma5' => 110.0, 'ma20' => 100.0,
            'volume' => self::VOLUME_MA20, 'volume_ma20' => (float) self::VOLUME_MA20,
        ];
    }

    /** 恰好落在中性帶邊界上的外資淨額（股）。 */
    private function sharesAtBand(): int
    {
        $band = config('health.chip.neutral_band_volume_share');

        $this->assertIsNumeric($band, 'health.chip.neutral_band_volume_share 必須存在，否則本測試量的不是門檻');

        return (int) round((float) $band * self::VOLUME_MA20 * 5);
    }

    /**
     * 由外資淨額序列建出 ChipFlowData 清單（升冪），投信與自營固定 0。
     *
     * @param  list<int>  $foreignNets
     * @return list<ChipFlowData>
     */
    private function flows(array $foreignNets): array
    {
        $out = [];

        foreach ($foreignNets as $i => $net) {
            $out[] = new ChipFlowData(sprintf('2026-06-%02d', $i + 16), $net, 0, 0, $net);
        }

        return $out;
    }
}
