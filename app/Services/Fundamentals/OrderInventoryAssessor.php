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

        $peer = $this->peers->sample($instrument, $data->industry);

        $assessment = $this->radar->assess(
            $data,
            peerRevenueGrowthMedian: $peer['median'],
            previousRating: $this->previousRating($instrument, $data->dataAsOf),
        );

        $this->persistRating($instrument, $assessment->rating->value);

        return ['assessment' => $assessment, 'peer_samples' => $peer['samples']];
    }

    /**
     * 前次評級＝**資料日嚴格早於本次**的最新一筆。
     *
     * 不能取「最新一列現在存的值」：同一天重跑分析時那會是自己剛寫的評級，
     * `ratingChange` 就永遠是 unchanged，等於這個欄位沒有意義。
     */
    private function previousRating(Instrument $instrument, ?string $dataAsOf): ?string
    {
        $query = Fundamental::query()
            ->where('instrument_id', $instrument->id)
            ->whereNotNull('order_inventory_rating');

        if ($dataAsOf !== null) {
            $query->where('data_as_of', '<', $dataAsOf);
        }

        return $query->orderByDesc('data_as_of')->orderByDesc('id')->value('order_inventory_rating');
    }

    /**
     * 只更新「序列真正落地的那一列」，**不是「最新的任何一列」**，也**不新增列**。
     * 新增列會讓同一個資料日出現兩筆，污染估值分位的樣本。
     *
     * 必須加 whereNotNull('order_inventory')：單看 data_as_of 最新不夠——
     * FundamentalsService 的負快取列（估值抓取失敗、序列也抓不到時寫的節流列，
     * 或本測試情境下只帶評級、不帶序列的殘留列）一樣是「最新一筆」，卻不是
     * orderInventoryFor() 這次操作、真正帶著本次序列的那一列。評級寫到負快取列
     * 上等於評級沒有對應到任何序列，且會覆蓋掉更早、真正有序列那列本該保留的
     * 評級。「order_inventory 非 null」才是「序列真正落地」的可靠判準。
     */
    private function persistRating(Instrument $instrument, string $rating): void
    {
        Fundamental::query()
            ->where('instrument_id', $instrument->id)
            ->whereNotNull('order_inventory')
            ->orderByDesc('data_as_of')
            ->orderByDesc('id')
            ->limit(1)
            ->update(['order_inventory_rating' => $rating]);
    }
}
