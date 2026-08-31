<?php

namespace App\Data;

use App\Enums\DatasetStatus;
use App\Enums\FetchStatus;

/**
 * 一次擷取的結果。狀態層靠它區分「沒有財報」與「這次抓失敗」。
 */
final readonly class FetchResult
{
    /**
     * @param  array<string, DatasetStatus>  $datasetStatuses  dataset 名 => 狀態
     */
    public function __construct(
        public FetchStatus $status,
        public PeriodFactSet $periods,
        public array $datasetStatuses = [],
        public ?string $errorCategory = null,
    ) {}

    public static function failed(string $category, array $datasetStatuses = []): self
    {
        return new self(FetchStatus::Failed, PeriodFactSet::empty(), $datasetStatuses, $category);
    }

    public static function unsupported(string $category, array $datasetStatuses = []): self
    {
        return new self(FetchStatus::Unsupported, PeriodFactSet::empty(), $datasetStatuses, $category);
    }

    /** 只有 Complete 能寫入 raw cache——半包資料封存 24 小時，所有重試都只會命中同一份半包。 */
    public function isCacheable(): bool
    {
        return $this->status === FetchStatus::Complete;
    }
}
