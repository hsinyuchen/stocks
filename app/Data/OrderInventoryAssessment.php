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
     * @param  list<string>  $fixedCaveats  固定提示，需人工判斷
     * @param  list<string>  $proxySignals  台股存貨組成的代理推論
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
