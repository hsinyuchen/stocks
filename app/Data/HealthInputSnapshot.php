<?php

namespace App\Data;

use App\Enums\AssetType;

/**
 * 判讀的**全部輸入**，不可變。
 *
 * 存在理由：**呼叫同一個 scorer 不保證得到同一個答案。** 實測各消費端的
 * 技術面視窗不同——選股器 90 根、個股分析與問答 80 根、圖表 1300 根、
 * 個股頁首載 0 根（刻意瘦身）——而 KD 從輸入序列第一根以 50 播種，
 * 視窗不同可能讓尾值跨過門檻。同一檔股票因此會在不同頁面得到不同立場。
 *
 * 所以一致性要靠**固定輸入**，不能靠「大家都呼叫同一個類別」。
 *
 * **快照帶資料，不只帶 metadata。** 只帶 symbol 與各項日期的話，
 * 「同一份快照必產出相同判讀」這個不變式就無從驗證——兩個消費端拿著同樣的
 * metadata 仍可能各自去取到不同的資料。快照必須**決定**輸出，
 * 兩個 reader 才能是真正的純計算。
 * 所有消費端序列化同一份 snapshot 的結果；送進 prompt 的那份要隨
 * StockAnalysis 保存，否則幾天後頁面顯示新判讀、歷史分析的文字仍引用舊的。
 *
 * **不帶 `formulaVersion`。** 版本號描述的是「用哪一版公式算」，而公式是在
 * read 時從 config 套用的——它是**公式的屬性不是輸入資料的屬性**。放在快照上
 * 會製造第二個來源：快照填一個、reader 讀另一個，兩份都會被序列化保存，
 * config 一改而快照是舊的，存下來的紀錄裡兩個版本號就不一致，
 * 而那正是版本號要防的事。版本號只出現在
 * {@see LongTermRead::$formulaVersion} 一處。
 *
 * **帶 `assetType`。** ETF 與指數不是公司，公司財報的四個面向對它們全都不適用
 * ——而 `MarketResolver::region('0050.TW')` 回台股、`roe` 為 null，
 * 少了這個欄位就會落到「還沒累積」那條路，對使用者說「等一下就有」，
 * 但 ETF 永遠不會有 ROE。
 *
 * `cachedOnly` 明確分離取用政策：true 代表這份 snapshot 只由已快取資料組成、
 * 一次上游都沒打。呈現層要據此說明「這份判讀可能不是最新的」。
 */
final readonly class HealthInputSnapshot
{
    /**
     * @param  array<string, mixed>  $indicators  TechnicalIndicatorService::calculate() 的輸出
     * @param  list<ChipFlowData>  $chipFlows
     * @param  array<string, array{value: float, percentile: float, min: float, median: float, max: float, samples: int}>|null  $valuationPercentiles
     */
    public function __construct(
        public string $symbol,
        public string $market,
        public int $bars,
        public array $indicators = [],
        public array $chipFlows = [],
        public ?FundamentalsData $fundamentals = null,
        public ?OrderInventoryMetrics $metrics = null,
        public ?array $valuationPercentiles = null,
        public ?string $industryBucket = null,
        public ?string $priceAsOf = null,
        public ?string $chipAsOf = null,
        public ?string $fundamentalsAsOf = null,
        public ?string $financialPeriod = null,
        public bool $cachedOnly = true,
        public AssetType $assetType = AssetType::Stock,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'market' => $this->market,
            'bars' => $this->bars,
            'price_as_of' => $this->priceAsOf,
            'chip_as_of' => $this->chipAsOf,
            'fundamentals_as_of' => $this->fundamentalsAsOf,
            'financial_period' => $this->financialPeriod,
            'cached_only' => $this->cachedOnly,
            'asset_type' => $this->assetType->value,
        ];
    }
}
