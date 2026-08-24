<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryAssessment;
use App\Models\Fundamental;
use App\Models\Instrument;

/**
 * 階段 2 評級引擎的 IO 邊界。
 *
 * `OrderInventoryRadar` 與其兩個依賴都是純計算、零 IO，那是為了能用注入的假序列
 * 精確測試每個評級分支。本類別在外面包一層：取序列、取同業樣本、取前次評級、
 * 跑評級、寫回本次評級。所有需要資料庫的事都只發生在這裡。
 */
class OrderInventoryAssessor
{
    public function __construct(
        private readonly FundamentalsService $fundamentals,
        private readonly OrderInventoryRadar $radar,
        private readonly OrderInventoryPeerSampler $peers,
    ) {}

    /**
     * 同業樣本數沒有放進 `OrderInventoryAssessment`（那是純計算層的 DTO），
     * 但呈現層必須說得出「同業樣本 N 檔」而不是讓使用者以為系統看過整個產業，
     * 所以一併回傳，避免呼叫端要記得多呼叫一個方法。
     *
     * @return array{assessment: OrderInventoryAssessment, peer_samples: int}|null
     */
    public function forInstrument(Instrument $instrument): ?array
    {
        $data = $this->fundamentals->orderInventoryFor($instrument);

        if ($data === null || ! $data->hasAny()) {
            return null;
        }

        // 先解析出「序列真正落地的那一列」，前次評級與寫回都以它為基準。
        // 兩件事因此只認定一次「本次」，不會各查各的而分岔。
        $target = $this->targetRow($instrument);

        $peer = $this->peers->sample($instrument, $data->industry);

        $assessment = $this->radar->assess(
            $data,
            peerRevenueGrowthMedian: $peer['median'],
            previousRating: $this->previousRating($instrument, $target),
        );

        $this->persistRating($target, $assessment->rating->value);

        return ['assessment' => $assessment, 'peer_samples' => $peer['samples']];
    }

    /**
     * 本次序列落地的那一列：`order_inventory` 非 null 的最新一列。
     *
     * 必須加 whereNotNull('order_inventory')：單看 data_as_of 最新不夠——
     * FundamentalsService 的負快取列（估值抓取失敗、序列也抓不到時寫的節流列，
     * 或只帶評級、不帶序列的殘留列）一樣是「最新一筆」，卻不是 orderInventoryFor()
     * 這次操作、真正帶著本次序列的那一列。評級寫到負快取列上等於評級沒有對應到
     * 任何序列，且會覆蓋掉更早、真正有序列那列本該保留的評級。
     * 「order_inventory 非 null」才是「序列真正落地」的可靠判準。
     *
     * instrument_id 限縮不可省：少了它，`orderByDesc('data_as_of')->first()` 會挑到
     * **別檔**最近落地的那一列（選股器批次掃描時同業的列天天都比自己新），
     * 評級因此被寫到別人身上、前次評級也拿別人的來比。
     */
    private function targetRow(Instrument $instrument): ?Fundamental
    {
        return Fundamental::query()
            ->where('instrument_id', $instrument->id)
            ->whereNotNull('order_inventory')
            ->orderByDesc('data_as_of')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 前次評級＝**資料日嚴格早於本次落地列**的最新一筆評級。
     *
     * 「本次」一律取自目標列的 `data_as_of`，**不能用 DTO 的 `dataAsOf`**：兩者同名
     * 不同語意。DB 欄位在台股是 PER 日期（每日更新），DTO 欄位是最新季末日（整季
     * 不變，且季報約在季末後 45 天才送件），落差最多約 5 個月（見
     * FundamentalsService::persistOrderInventory 的 docblock）。拿季末日去比 PER
     * 日期，會把季末以來的**每一列**排掉、包含真正的前一個資料日：台股的
     * ratingChange 會整季凍結，日內的 C→B 升評永遠顯示不出來；剛開始有資料的標的
     * 季末以前根本沒有列，ratingChange 還會恆為 'first'。美股兩者同源所以看不出
     * 問題，而台股是本專案主市場。
     *
     * 嚴格早於（`<` 而非 `<=`）：同一個資料日重跑分析時，目標列上存的是自己上一輪
     * 剛寫的評級，把它當前次會讓 ratingChange 永遠是 unchanged。
     *
     * whereNotNull('order_inventory_rating') 不可省：`value()` 取的是排序後第一列的
     * 欄位值，少了這個述詞，只要較新的那一列還沒有評級（例如當天先落地了序列、
     * 評級因目標列失敗而未寫入），取回的就是 null，ratingChange 會把有前次評級的
     * 標的錯報成 'first'。要的是「最近一次**有評級**的資料日」，不是「最近一個資料日
     * 上的評級欄位」。
     *
     * 目標列不存在時一律視為首次評級：沒有落地列就無從界定「本次」，此時放寬成
     * 「最新一筆評級」會把某一列的舊評級當成前次，而本次評級根本不會被寫進任何一列。
     */
    private function previousRating(Instrument $instrument, ?Fundamental $target): ?string
    {
        if ($target === null) {
            return null;
        }

        return Fundamental::query()
            ->where('instrument_id', $instrument->id)
            ->whereNotNull('order_inventory_rating')
            ->where('data_as_of', '<', $target->data_as_of)
            ->orderByDesc('data_as_of')
            ->orderByDesc('id')
            ->value('order_inventory_rating');
    }

    /**
     * 只更新目標列，**不新增列**——新增列會讓同一個資料日出現兩筆，污染估值分位的樣本。
     *
     * 目標列的 `failed_at` 非 null 時不寫評級：`FundamentalsService::persist()` 成功時
     * 一律把 failed_at 清成 null，只有 handleFailure() 會設值，所以 failed_at 非 null
     * 可靠地代表「這一列最後一次被觸碰是失敗、現在是以 last-known-good 的身分被端出來」。
     * 那種情況下 orderInventoryFor() 交出的是**舊序列**，而 assess() 的時效判定吃的是
     * now()——同一份舊序列放到今天可能已經 too_old 而評成 insufficient，寫回去就把那列
     * 歷史列上原本正確的評級回溯改掉，破壞 migration 承諾的「歷史列自然累積成評級軌跡」。
     * 今天算出的評級不屬於這一列的觀測日，就不該寫進去。
     */
    private function persistRating(?Fundamental $target, string $rating): void
    {
        if ($target === null || $target->failed_at !== null) {
            return;
        }

        $target->update(['order_inventory_rating' => $rating]);
    }
}
