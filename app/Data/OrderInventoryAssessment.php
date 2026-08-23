<?php

namespace App\Data;

use App\Enums\OrderInventoryRating;

/**
 * 一次訂單／庫存判斷的完整輸出。
 *
 * counterEvidence / fixedCaveats / proxySignals 由 Task 5 填入；本 DTO 先把位置
 * 留好，避免消費端在兩個階段之間看到不同的形狀。
 */
final readonly class OrderInventoryAssessment
{
    /**
     * @param  array<string, ?bool>  $conditions  C1…C10
     * @param  list<string>  $negativeSignals  觸發規則 2 的負面項
     * @param  array{as_of: ?string, period: ?string, revenue_month: ?string, lagging: bool, too_old: bool}  $freshness
     * @param  list<string>  $missingForA  升到 A 還缺什麼（人工查證清單）
     * @param  list<string>  $counterEvidence  依數據觸發的反證
     * @param  list<string>  $fixedCaveats  提示清單：config 的固定條目，外加依資料狀況追加的說明；長度不固定，一律全部渲染
     * @param  list<string>  $proxySignals  存貨組成訊號：這一季讀得到實測值（目前只有美股）就用實測值，讀不到才回落代理推論（台股恆走這條，美股也可能因缺季而走這條）
     * @param  string  $ratingChange  'first'｜'unchanged'｜'upgraded'｜'downgraded'
     */
    public function __construct(
        public OrderInventoryRating $rating,
        public OrderInventoryMetrics $metrics,
        public array $conditions = [],
        public array $negativeSignals = [],
        public string $industryBucket = 'unknown',
        public ?string $industryNote = null,
        public array $freshness = [
            'as_of' => null, 'period' => null, 'revenue_month' => null,
            'lagging' => false, 'too_old' => false,
        ],
        public array $missingForA = [],
        public array $counterEvidence = [],
        public array $fixedCaveats = [],
        public array $proxySignals = [],
        public ?string $previousRating = null,
        public string $ratingChange = 'first',
    ) {}
}
