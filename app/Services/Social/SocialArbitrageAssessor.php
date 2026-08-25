<?php

namespace App\Services\Social;

use App\Data\SocialArbitrage;
use App\Models\ChipFlow;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Services\Fundamentals\OrderInventoryAssessor;
use App\Support\MarketResolver;
use Carbon\CarbonImmutable;

/**
 * 社交套利分類的 IO 邊界：把四條輸入（新聞熱度、股價漲幅、法人淨買超佔同期成交量比、
 * 營收驗證與毛利率 QoQ）取齊，交給純計算的 {@see SocialArbitrageClassifier}。
 * 與階段 2 的 OrderInventoryAssessor 同一模式——所有需要資料庫的事都只發生在這裡。
 *
 * **全程只讀，一次上游都不打。** 股價與籌碼刻意**直接讀 model**，不走既有的兩個服務：
 *
 * - `MarketDataProvider::dailyPrices()`：外層的 `CachedMarketDataProvider` 在
 *   「不新鮮」或「涵蓋度不足」時會**就地抓上游並寫 DB**。
 * - `ChipDataService::forInstrument()`：台股路徑在 `isFresh()` 為 false 時
 *   **呼叫 provider 打 FinMind**。
 *
 * 本類別的消費端是警報評估，它跑在首頁的**同步 web 請求**裡，而 PHP 的
 * `max_execution_time` 不是例外、`try/catch` 攔不到；那條路徑也沒有選股掃描
 * （scan_time_budget_seconds）或快報 job（timeout）那種總量預算，只能從「不抓」
 * 這一端解。
 * 「只聚合已快取的資料」的先例是階段 3 的 `OrderInventoryPeerSampler`。
 *
 * 營收與毛利兩條腿走 `OrderInventoryAssessor::seriesSignalsFor()`，**不是**
 * `cachedFor()`。兩者都只讀，差別在新鮮度那把尺：`cachedFor()` 用估值的每日 TTL
 * （「今天盤後的 PER 公佈了沒」），而這兩條腿要的是**季財報＋月營收**，一天不會
 * 有新東西。套每日 TTL 的後果不是「保守地少講一句」——個股頁在社交套利之前會先
 * 跑一次會抓取的入口、順手刷新 fetched_at，選股器與首頁警報沒有這個順序保護，
 * 於是同一檔標的在個股頁上是「疑似假訊號」、在選股器裡卻被當成「早期」篩給使用者。
 * `seriesSignalsFor()` 用序列自己的視窗，把那個順序依賴解掉；它同時是本類別
 * 「全程只讀」這句話成立的前提——`cachedFor()` 每次呼叫都會寫回一次評級。
 *
 * 拿不到就當沒有：該條腿回 `null`（「算不出來」），等下一次個股分析／選股掃描把
 * 資料抓進快取即可。**這個約束在測試上是無聲的**——把讀取換回那兩個服務，功能
 * 照樣正確，只有 SocialArbitrageAssessorTest 的「四項快取全都不新鮮時仍零上游」
 * 那條會紅。
 *
 * 無狀態（不記憶化任何查詢），因此**刻意不做容器綁定**，交給 Laravel 自動解析：
 * 加一個沒有作用的 `scoped` 只是為了與 NewsHeatCalculator／OrderInventoryPeerSampler
 * 對稱，而那兩者是為了各自的 memo 才需要生命週期。
 */
class SocialArbitrageAssessor
{
    public function __construct(
        private readonly NewsHeatCalculator $heat,
        private readonly SocialArbitrageClassifier $classifier,
        private readonly OrderInventoryAssessor $orderInventory,
    ) {}

    /**
     * 回傳型別**不可為 nullable**：classifier 最差也回 `Insufficient`，沒有任何情形
     * 需要回 null。留著 `?` 會讓呼叫端寫出永遠不成立的 `=== null` 分支，或更糟——
     * 把 null 與 `Insufficient` 當成兩種「說不出來」。
     *
     * `$now` 一路傳下去，內部不再呼叫一次 `CarbonImmutable::now()`：跨午夜時兩條腿
     * 會落在不同視窗，而且測試會壞在日曆上而不是壞在程式碼改動上。
     */
    public function forInstrument(Instrument $instrument, ?CarbonImmutable $now = null): SocialArbitrage
    {
        $now = $now ?? CarbonImmutable::now();

        // 熱度的新期是 daysAgo 0 .. windowDays - 1（見 NewsHeatCalculator::forSymbol()）。
        // 股價腿與籌碼腿必須用**完全相同**的這組日曆邊界，「同視窗」三個字才是真的。
        $windowDays = $this->windowDays();
        $from = $now->subDays($windowDays - 1)->startOfDay();
        $to = $now->endOfDay();

        // 只呼叫一次：營收與毛利兩條腿共用同一份快照。呼叫兩次除了多一輪查詢，
        // 還可能拿到不一致的兩份快照。
        $series = $this->orderInventory->seriesSignalsFor($instrument);

        return $this->classifier->classify(
            heat: $this->heat->forSymbol($instrument->symbol, $now),
            priceChange: $this->priceChange($instrument, $from, $to),
            foreignShare: $this->foreignShare($instrument, $from, $to),
            // 「序列拿不到」與「C1 算不出來」都是 null：兩者對呈現層是同一件事
            // （營收腿不可評估）。
            revenueVerified: $series['revenue_verified'],
            grossMarginQoqPp: $series['gross_margin_qoq_pp'],
        );
    }

    /**
     * 視窗內的**首尾收盤**漲幅。
     *
     * 用日曆區間而**不是「最後 N 根」**：後者是交易日語意，遇連假就與熱度的日曆
     * 視窗對不齊，兩條腿量的根本不是同一段時間。
     *
     * 區間內 0 或 1 根 K 棒回 null——一根算不出變化，拿同一根當首尾會得到 0%，
     * 而 0% 是「這段期間沒漲」這個實質宣稱，不是「算不出來」。
     * 首根收盤 <= 0 同樣回 null：除以 0 或負數得不出有意義的漲幅。
     */
    private function priceChange(Instrument $instrument, CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $closes = DailyPrice::query()
            ->where('instrument_id', $instrument->id)
            ->whereBetween('priced_at', [$from, $to])
            ->orderBy('priced_at')
            // 同一天理論上只有一列（unique 索引），排序仍加 id 讓結果完全確定。
            ->orderBy('id')
            ->pluck('close');

        if ($closes->count() < 2) {
            return null;
        }

        $first = (float) $closes->first();
        $last = (float) $closes->last();

        if ($first <= 0.0) {
            return null;
        }

        return ($last - $first) / $first;
    }

    /**
     * 外資淨買超佔**同期成交量**的比例。
     *
     * 分母是成交量而不是股本：本專案沒有任何流通股數來源（instruments、fundamentals、
     * 既有抓取路徑都沒有），而成交量與三大法人買賣超單位一致（FinMind 的
     * `Trading_Volume` 與買賣超皆以「股」計），不需換算，且讓三條腿落在同一段日曆視窗上。
     *
     * 非台股一律 null（美股沒有三大法人資料），判準用 `MarketResolver::isTaiwan()`
     * ——與 `ChipDataService` 同一個。
     *
     * **視窗內查無籌碼列時必須回 null，不能回 `sum()` 給的 0**：`0` 代表「有資料且
     * 淨買超為零」，`null` 代表「沒有這種資料」，兩者在輸出上長得不一樣（見
     * {@see SocialArbitrage}）。因此先取列數再取合計，而不是只看合計值。
     */
    private function foreignShare(Instrument $instrument, CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        if (! MarketResolver::isTaiwan($instrument->symbol)) {
            return null;
        }

        $flows = ChipFlow::query()
            ->where('instrument_id', $instrument->id)
            ->whereBetween('traded_at', [$from, $to])
            ->toBase()
            ->selectRaw('count(*) as row_count, sum(foreign_net) as net_total')
            ->first();

        if ($flows === null || (int) $flows->row_count === 0) {
            return null;
        }

        $volume = (float) DailyPrice::query()
            ->where('instrument_id', $instrument->id)
            ->whereBetween('priced_at', [$from, $to])
            ->sum('volume');

        // 同期沒有成交量（無行情列，或整段停牌）時比例無定義。
        if ($volume <= 0.0) {
            return null;
        }

        return (float) $flows->net_total / $volume;
    }

    /**
     * 視窗長度一律從 config 取，不得寫死 14——熱度那一側就是讀這個鍵，兩邊各寫一份
     * 會在調整設定時無聲分岔。
     *
     * 缺鍵或非數值一律拋錯，不做裸 `(int)` 轉型（`(int) null === 0`）：視窗變 0
     * 會讓 `subDays(-1)` 把區間推到**未來**，兩條腿於是恆為不可評估，而不會有任何
     * 錯誤訊號可供察覺。理由與 {@see SocialArbitrageClassifier::requireFloat()} 同。
     */
    private function windowDays(): int
    {
        $value = config('order_inventory.social.heat_window_days');

        if (! is_numeric($value) || (int) $value < 1) {
            throw new \RuntimeException('order_inventory.social.heat_window_days config 缺失或非正整數，無法界定社交套利的視窗。');
        }

        return (int) $value;
    }
}
