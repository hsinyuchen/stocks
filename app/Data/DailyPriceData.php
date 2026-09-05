<?php

namespace App\Data;

final readonly class DailyPriceData
{
    public function __construct(
        public string $symbol,
        public string $date,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public int $volume,
        /**
         * 盤中未完成棒：high／low／close／volume 都還會變。只有當日棒來源會標成 true。
         * 圖表照畫（與看盤軟體一致）；拿它做決策的地方要略過：警報一次性觸發、
         * 分析結果落 DB、以成交量當規模基準的計算。見 CompletedBars。
         */
        public bool $partial = false,
    ) {}
}
