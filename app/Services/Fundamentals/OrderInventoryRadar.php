<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryAssessment;
use App\Data\OrderInventoryData;
use App\Data\OrderInventoryMetrics;
use App\Data\QuarterlyFinancials;
use App\Enums\OrderInventoryRating;
use Carbon\CarbonImmutable;

/**
 * 訂單／庫存／進貨備料判斷。純計算：不碰資料庫、網路、快取、LLM。
 *
 * 十個條件全部回 ?bool，**null 代表不可評估而非不成立**。這是本管線最容易寫壞的
 * 地方：把算不出來壓成 false，會讓串聯規則 2（¬C1 且至少兩項負面）誤觸，
 * 所有缺資料的標的都被推向 C 級。
 *
 * 同業中位數由呼叫端傳入，本類別不自己去查——保持零 IO 才能用注入的假序列
 * 精確測試每個評級分支。
 */
class OrderInventoryRadar
{
    public function __construct(
        private readonly OrderInventoryMetricsCalculator $calculator = new OrderInventoryMetricsCalculator,
        private readonly OrderInventoryIndustryPolicy $industry = new OrderInventoryIndustryPolicy,
    ) {}

    /**
     * @return array<string, ?bool> 鍵為 C1…C10
     */
    public function conditions(OrderInventoryMetrics $m, ?float $peerRevenueGrowthMedian = null): array
    {
        $t = (array) config('order_inventory.thresholds', []);

        return [
            'C1' => $this->revenueStreakMet($m, $t),
            'C2' => $m->grossMarginQoqPp === null
                ? null
                : $m->grossMarginQoqPp >= (float) $t['gross_margin_stable_pp'],
            'C3' => $this->inventoryDaysStable($m, $t),
            'C4' => $this->anyThreshold([
                [$m->inventoriesQoq, (float) $t['inventory_surge_qoq']],
                [$m->inventoriesYoy, (float) $t['inventory_surge_yoy']],
            ]),
            'C5' => $this->anyThreshold([
                [$m->dpoChangeDays, (float) $t['payable_days_up']],
                [$m->dpoChangeRatio, (float) $t['payable_ratio_up']],
            ]),
            'C6' => $this->contractLiabilitiesUp($m),
            'C7' => $this->anyThreshold([
                [$m->dsoChangeDays, (float) $t['receivable_days_up']],
                [$m->dsoChangeRatio, (float) $t['receivable_ratio_up']],
            ]),
            'C8' => $this->cashFlowQuality($m, $t),
            'C9' => $m->capexToRevenue === null || $m->capexToRevenueTrailingAverage === null
                ? null
                : $m->capexToRevenue > $m->capexToRevenueTrailingAverage,
            'C10' => $peerRevenueGrowthMedian === null || $m->revenueYoy === null
                ? null
                : $m->revenueYoy > $peerRevenueGrowthMedian,
        ];
    }

    /**
     * C1 的門檻依基準而不同：台股數月營收 YoY 連正月數，美股無月營收，
     * 改數季營收 YoY 連正季數。用同一個數字去比兩種基準會兩邊都錯。
     *
     * @param  array<string, mixed>  $t
     */
    private function revenueStreakMet(OrderInventoryMetrics $m, array $t): ?bool
    {
        if ($m->revenueGrowthStreak === null) {
            return null;
        }

        return match ($m->revenueGrowthBasis) {
            'monthly' => $m->revenueGrowthStreak >= (int) $t['revenue_streak_months'],
            'quarterly' => $m->revenueGrowthStreak >= (int) $t['revenue_streak_quarters'],
            default => null,
        };
    }

    /**
     * C3：DIO 下降本身即通過；上升則需落在穩定區間內（天數或比率任一成立）。
     *
     * @param  array<string, mixed>  $t
     */
    private function inventoryDaysStable(OrderInventoryMetrics $m, array $t): ?bool
    {
        if ($m->dioChangeDays === null && $m->dioChangeRatio === null) {
            return null;
        }

        if (($m->dioChangeDays !== null && $m->dioChangeDays < 0.0)
            || ($m->dioChangeRatio !== null && $m->dioChangeRatio < 0.0)) {
            return true;
        }

        return ($m->dioChangeDays !== null && abs($m->dioChangeDays) <= (float) $t['dio_stable_days'])
            || ($m->dioChangeRatio !== null && abs($m->dioChangeRatio) <= (float) $t['dio_stable_ratio']);
    }

    /**
     * C6：合約負債（預收款）增加。
     *
     * 比率在基期為 0 時數學上無定義，contractLiabilitiesQoq 因此是 null——但
     * 「預收款從無到有」是框架語意裡最強的接單訊號之一，照原樣只看比率會讓
     * 這個訊號白白棄權。contractLiabilitiesFromZero 這個旗標已經在計算層排除
     * 「基期未揭露」與「缺季」兩種假陽性，這裡直接信任即可，不重判。
     */
    private function contractLiabilitiesUp(OrderInventoryMetrics $m): ?bool
    {
        if ($m->contractLiabilitiesFromZero) {
            return true;
        }

        return $m->contractLiabilitiesQoq === null ? null : $m->contractLiabilitiesQoq > 0.0;
    }

    /**
     * C8：現金流品質。比率低於下限，或 OCF 為負。
     * 後者不可省——淨利同為負時比率會變成正數，看起來反而健康。
     *
     * @param  array<string, mixed>  $t
     */
    private function cashFlowQuality(OrderInventoryMetrics $m, array $t): ?bool
    {
        if ($m->operatingCashFlowNegative) {
            return true;
        }

        return $m->ocfToNetIncome === null
            ? null
            : $m->ocfToNetIncome < (float) $t['ocf_to_net_income_floor'];
    }

    /**
     * 任一「值 ≥ 門檻」即成立。全部為 null 才回 null——有一個能算就要給答案，
     * 不能因為另一個缺就整項放棄。
     *
     * @param  list<array{0: ?float, 1: float}>  $pairs
     */
    private function anyThreshold(array $pairs): ?bool
    {
        $evaluable = false;

        foreach ($pairs as [$value, $threshold]) {
            if ($value === null) {
                continue;
            }

            $evaluable = true;

            if ($value >= $threshold) {
                return true;
            }
        }

        return $evaluable ? false : null;
    }

    /**
     * 評級串聯（first-match）。public 是為了讓性質測試能窮舉全部條件組合——
     * 「A 級永不自動給予」必須被證明，而不是靠讀程式碼相信。
     *
     * @param  array<string, ?bool>  $conditions
     */
    public function rate(array $conditions, ?float $grossMarginQoqPp): OrderInventoryRating
    {
        $t = (array) config('order_inventory.thresholds', []);

        // 規則 2 只在 C1 **明確為 false** 時觸發。null 代表算不出來，
        // 拿它當「不成立」會讓所有缺資料的標的被系統性推向 C 級。
        if (($conditions['C1'] ?? null) === false
            && count($this->negativeSignals($conditions, $grossMarginQoqPp, $t)) >= 2) {
            return OrderInventoryRating::C;
        }

        $required = ($conditions['C1'] ?? null) === true
            && ($conditions['C2'] ?? null) === true
            && ($conditions['C7'] ?? null) === false
            && ($conditions['C8'] ?? null) === false;

        $anySupport = ($conditions['C4'] ?? null) === true
            || ($conditions['C5'] ?? null) === true
            || ($conditions['C6'] ?? null) === true;

        // 不確定時不給較高評級：required 的任一項為 null 都落到 B。
        return $required && $anySupport ? OrderInventoryRating::BPlus : OrderInventoryRating::B;
    }

    public function assess(
        OrderInventoryData $data,
        ?float $peerRevenueGrowthMedian = null,
        ?string $previousRating = null,
        ?CarbonImmutable $now = null,
    ): OrderInventoryAssessment {
        $now ??= CarbonImmutable::now();
        $metrics = $this->calculator->calculate($data);
        $industry = $this->industry->evaluate($data);
        $freshness = $this->freshness($metrics, $now);

        // 「這一季的存貨組成讀得到嗎」只有一個判準：actualCompositionSignals()
        // 實際輸出了東西。算一次餵給兩個消費端（代理矩陣的回落、A 級查證清單），
        // 各判一次遲早會分岔。
        $compositionSignals = $this->actualCompositionSignals($data, $metrics);

        $base = fn (OrderInventoryRating $rating, array $extra = []): OrderInventoryAssessment => new OrderInventoryAssessment(...array_merge([
            'rating' => $rating,
            'metrics' => $metrics,
            'industryBucket' => $industry['bucket'],
            'industryNote' => $industry['note'],
            'freshness' => $freshness,
            'missingForA' => $this->missingForA(),
            'fixedCaveats' => (array) config('order_inventory.narrative.fixed_caveats', []),
            'previousRating' => $previousRating,
            'ratingChange' => $this->ratingChange($rating, $previousRating),
        ], $extra));

        // 串聯 0：缺關鍵科目或資料過舊。
        if ($this->keyLineItemsMissing($data, $industry['bucket']) || $freshness['too_old']) {
            return $base(OrderInventoryRating::Insufficient);
        }

        // 串聯 1：產業不適用。
        if (! $industry['applicable']) {
            return $base(OrderInventoryRating::NotApplicable);
        }

        $conditions = $this->conditions($metrics, $peerRevenueGrowthMedian);
        $rating = $this->rate($conditions, $metrics->grossMarginQoqPp);

        return $base($rating, [
            'conditions' => $conditions,
            'negativeSignals' => $this->negativeSignals(
                $conditions,
                $metrics->grossMarginQoqPp,
                (array) config('order_inventory.thresholds', []),
            ),
            'counterEvidence' => $this->counterEvidence($metrics, $conditions, $peerRevenueGrowthMedian),
            'proxySignals' => $this->inventoryCompositionSignals($data, $metrics, $compositionSignals),
        ]);
    }

    /**
     * 依實際數據觸發的反證。框架第 8 節要求每次評級至少列一項可能推翻結論的證據——
     * 只講支持結論的訊號，等於把分析變成事後合理化。
     *
     * @param  array<string, ?bool>  $conditions
     * @return list<string>
     */
    private function counterEvidence(
        OrderInventoryMetrics $m,
        array $conditions,
        ?float $peerRevenueGrowthMedian,
    ): array {
        $evidence = [];

        if ($m->relatedPartyPayableShareQoqPp !== null && $m->relatedPartyPayableShareQoqPp > 0.0) {
            $evidence[] = 'related_party_payables_rising';
        }

        // 同業與自身同步走弱：這是產業現象，不是公司特定的備料訊號。
        if ($peerRevenueGrowthMedian !== null && $peerRevenueGrowthMedian <= 0.0
            && $m->revenueYoy !== null && $m->revenueYoy <= 0.0) {
            $evidence[] = 'peer_wide_deterioration';
        }

        if ($m->inventoriesQoq !== null && $m->inventoriesQoq > 0.0
            && $m->revenueQoq !== null && $m->revenueQoq <= 0.0) {
            $evidence[] = 'inventory_up_revenue_flat';
        }

        if (($conditions['C9'] ?? null) === true && $m->revenueYoy !== null && $m->revenueYoy <= 0.0) {
            $evidence[] = 'capex_up_revenue_flat';
        }

        return $evidence;
    }

    /**
     * 存貨組成訊號。**兩市場的確定性層級不同，措辭必須分開。**
     *
     * 台股的財報附註未公開於資料源，只能從「存貨／應付／營收／DSO／合約負債」的
     * 組合推方向，因此固定冠上不確定性前綴；美股有實際的原料／在製品／製成品
     * 數字，直接讀。把兩者寫成一樣，會讓使用者把推論當實測——設計文件把這列為
     * 本功能的第二號風險。
     *
     * @param  list<string>  $compositionSignals  已算好的實測組成訊號，空陣列代表這一季讀不到
     * @return list<string>
     */
    private function inventoryCompositionSignals(
        OrderInventoryData $data,
        OrderInventoryMetrics $m,
        array $compositionSignals,
    ): array {
        // 有實測值就用實測值；讀不到才回落代理矩陣。
        //
        // **不可改回 `if ($data->inventoryCompositionAvailable) return ...;`**：
        // 那個旗標在階段 1 的語意是「12 季視窗內任一季有組成」，不是「這一季有」。
        // 美股的組成標籤常只出現在年報 frame，季報 frame 缺席是常態，最新季與 QoQ
        // 基期只要缺一邊，旗標仍是 true 而實測值算不出來——早退等於在最典型的情境
        // 把塞貨／去化不良這類負面訊號整個吞掉，同時讓 proxy_prefix_us
        // （「本次未取得存貨組成揭露」）這條專為此情境寫的文案永遠不可達。
        if ($compositionSignals !== []) {
            return $compositionSignals;
        }

        // 存貨沒有增加時，這個矩陣沒有可談的東西——硬給方向是編造。
        if ($m->inventoriesQoq === null || $m->inventoriesQoq <= 0.0) {
            return [];
        }

        $latest = $data->latestQuarter();

        if ($latest === null) {
            return [];
        }

        $previousQuarter = $this->previousQuarter($data, $m);
        $readings = [];

        // 矩陣第一列是**三腿**：存貨↑ + 應付↑ + 後續月營收↑ → 較像提前備料。
        //
        // 「應付↑」比的是應付帳款餘額本身，不是 DPO 天數：DPO 的分母是營業成本，
        // 成本一下滑天數就會上升，而應付帳款可以一動不動——照天數判會對使用者
        // 講出「應付帳款同步增加」這種與事實相反的話。
        //
        // 文案講的是「月營收持續成長」，所以這裡必須是 === 'monthly'，不能只排除 'none'。
        // revenueGrowthStreak() 在月營收缺資料（monthlyRevenue === []）時會靜默 fallback
        // 到季營收 YoY 連正數，basis 會落在 'quarterly' 而不是 'none'——用 !== 'none' 判斷
        // 會讓一天月營收都沒用到就冒出「月營收持續成長」，把使用者從未提供的資料點講成事實。
        // 美股 basis 恆為 'quarterly'（SEC 無月營收），用 === 'monthly' 也順帶避免美股誤觸這條
        // 台股專屬文案。
        //
        // 「**後續**」與「**持續**」兩個字各自需要資料支撐，見 revenueMomentumAfterQuarter()。
        if ($previousQuarter !== null
            && $latest->accountsPayable !== null
            && $previousQuarter->accountsPayable !== null
            && $latest->accountsPayable > $previousQuarter->accountsPayable
            && $this->revenueMomentumAfterQuarter($m)) {
            $readings[] = (string) config('order_inventory.narrative.proxy_stocking_up');
        }

        if ($m->revenueQoq !== null && $m->revenueQoq < 0.0
            && $m->dsoChangeDays !== null && $m->dsoChangeDays > 0.0) {
            $readings[] = (string) config('order_inventory.narrative.proxy_channel_stuffing');
        }

        if ($m->contractLiabilitiesQoq !== null && $m->contractLiabilitiesQoq > 0.0) {
            $readings[] = (string) config('order_inventory.narrative.proxy_visibility');
        }

        if ($readings === []) {
            return [];
        }

        // 句號在組裝時統一補，config 的文案本身不帶句號——可讀性不該依賴
        // 每個人新增文案時都記得加標點。
        return [$this->proxyPrefix($data).implode('。', $readings).'。'];
    }

    /**
     * 代理矩陣第一列第三腿：「**後續**月營收**持續**成長」。
     *
     * 兩個副詞各自要有依據，否則就是對使用者宣稱沒驗證過的事：
     *
     * - 「後續」：streak 只從 latestRevenueMonth 往回走，不看季報期別。月營收停在
     *   季末之前時（台股月報與季報的入庫時點本來就會錯開），講的其實是季**內**
     *   甚至季**前**的月份。因此要求月份嚴格晚於最新季末。
     * - 「持續」：沿用 C1 的 revenue_streak_months，不另設一把較鬆的尺。同一份
     *   報告裡「營收動能成立」與「月營收持續成長」若用不同門檻，會出現 C1 不成立
     *   卻照樣宣稱持續成長的自相矛盾。
     */
    private function revenueMomentumAfterQuarter(OrderInventoryMetrics $m): bool
    {
        if ($m->revenueGrowthBasis !== 'monthly' || $m->revenueGrowthStreak === null) {
            return false;
        }

        $threshold = (int) ((array) config('order_inventory.thresholds', []))['revenue_streak_months'];

        if ($m->revenueGrowthStreak < $threshold) {
            return false;
        }

        $month = $this->parseDate($m->latestRevenueMonth);
        $quarterEnd = $this->parseDate($m->latestEndDate);

        // 期別本身缺席或格式壞掉時無從比對，寧可不講這句。
        return $month !== null && $quarterEnd !== null && $month->greaterThan($quarterEnd);
    }

    /**
     * 只解析日曆上真正存在的 YYYY-MM-DD。
     *
     * 上游（FinMindFundamentalsProvider、SecEdgarFinancialsProvider）對日期只做
     * (string) 轉型、無格式驗證，值又會經 DB 的 JSON 欄位往返，壞值進得來：
     * 'N/A' 會讓 Carbon::parse() 拋例外炸掉整個 job，'0000-00-00' 會被**靜默**
     * 解成合法日期，'' 則解成 now()。與
     * OrderInventoryMetricsCalculator::monthlyRevenueGrowthStreak() 同策略：
     * 形狀與日曆有效性都通過才解析，否則視為沒有這個日期。
     */
    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    /**
     * 代理推論的前綴。台美的「拿不到存貨組成」成因不同：台股是財報附註未公開於
     * 資料源，美股是本次沒取到 SEC tag。共用一條文案會把原因講錯。
     */
    private function proxyPrefix(OrderInventoryData $data): string
    {
        return (string) config(
            $data->market === 'us'
                ? 'order_inventory.narrative.proxy_prefix_us'
                : 'order_inventory.narrative.proxy_prefix',
        );
    }

    /**
     * 期別相鄰的前一季，取自指標層算好的 qoqBasePeriod。
     *
     * **不可改成「陣列倒數第二筆」**：序列允許缺季（SEC XBRL 缺 frame 是常態），
     * 倒數第二筆可能相隔兩季。指標層為此在缺季時拒絕計算 QoQ，呈現層若自己往回數，
     * 就會出現指標為 null、文字卻拿跨兩季的數字當「上一季」講的矛盾。共用同一個
     * 基期也保證兩層說法一致。
     */
    private function previousQuarter(OrderInventoryData $data, OrderInventoryMetrics $m): ?QuarterlyFinancials
    {
        return $m->qoqBasePeriod === null ? null : $data->quarter($m->qoqBasePeriod);
    }

    /**
     * 美股：直接讀財報揭露的存貨組成。原料與在製品增加而製成品未堆高，
     * 是框架 A 級條件之一的實測依據（但仍缺訂單公告，故評級仍封頂 B+）。
     *
     * @return list<string>
     */
    private function actualCompositionSignals(
        OrderInventoryData $data,
        OrderInventoryMetrics $m,
    ): array {
        $latest = $data->latestQuarter();
        $previousQuarter = $this->previousQuarter($data, $m);

        if ($latest === null || $previousQuarter === null) {
            return [];
        }

        $lines = [];

        foreach ([
            ['原料', $latest->inventoryRawMaterials, $previousQuarter->inventoryRawMaterials],
            ['在製品', $latest->inventoryWorkInProcess, $previousQuarter->inventoryWorkInProcess],
            ['製成品', $latest->inventoryFinishedGoods, $previousQuarter->inventoryFinishedGoods],
        ] as [$label, $current, $previous]) {
            if ($current === null || $previous === null) {
                continue;
            }

            $lines[] = sprintf(
                '%s%s（%s → %s）',
                $label,
                match (true) {
                    $current > $previous => '增加',
                    $current < $previous => '減少',
                    default => '持平',
                },
                number_format($previous),
                number_format($current),
            );
        }

        if ($lines === []) {
            return [];
        }

        return [sprintf(
            (string) config('order_inventory.narrative.actual_composition_prefix'),
            $previousQuarter->period,
            $latest->period,
        ).implode('、', $lines)];
    }

    /**
     * 規則 2 的負面項。
     *
     * @param  array<string, ?bool>  $conditions
     * @param  array<string, mixed>  $t
     * @return list<string>
     */
    private function negativeSignals(array $conditions, ?float $grossMarginQoqPp, array $t): array
    {
        $signals = [];

        if (($conditions['C3'] ?? null) === false) {
            $signals[] = 'dio_rising';
        }

        if (($conditions['C7'] ?? null) === true) {
            $signals[] = 'dso_rising';
        }

        if (($conditions['C8'] ?? null) === true) {
            $signals[] = 'weak_operating_cash_flow';
        }

        if ($grossMarginQoqPp !== null
            && $grossMarginQoqPp < (float) $t['gross_margin_deteriorating_pp']) {
            $signals[] = 'gross_margin_deteriorating';
        }

        return $signals;
    }

    /**
     * 缺關鍵科目：營收或營業成本缺，或存貨缺——但產業桶已判「不適用」時一律不算。
     *
     * 逃生口涵蓋全部關鍵科目而不只 inventories：產業已經判定框架不適用（銀行、
     * 純軟體…），此時不論缺的是存貨還是營收／營業成本，都是產業性質使然而非
     * 資料缺漏，說成「資料不足」會誤導使用者去追一份根本不存在的資料。
     * 例：美股純軟體公司連 COGS 都取不到，若只放行 inventories，仍會被誤報
     * 「資料不足」。裁決見 .superpowers/sdd/task-4-fix-brief.md 修正 1
     * ——不影響時效判定：not_applicable 產業 + 資料過舊仍走串聯 0 的 too_old 半支。
     */
    private function keyLineItemsMissing(OrderInventoryData $data, string $industryBucket): bool
    {
        if ($industryBucket === 'not_applicable') {
            return false;
        }

        $latest = $data->latestQuarter();

        if ($latest === null || $latest->revenue === null || $latest->costOfGoodsSold === null) {
            return true;
        }

        return $latest->inventories === null;
    }

    /**
     * 資料時效。框架第 2 條原則：財報落後實際訂單 1–2 季，本框架偏驗證工具而非
     * 領先指標。沒有這塊輸出，使用者會把落後兩季的資料當即時訊號。
     *
     * @return array{as_of: ?string, period: ?string, revenue_month: ?string, lagging: bool, too_old: bool}
     */
    private function freshness(OrderInventoryMetrics $metrics, CarbonImmutable $now): array
    {
        $f = (array) config('order_inventory.freshness', []);
        $age = $metrics->latestEndDate === null
            ? null
            : CarbonImmutable::parse($metrics->latestEndDate)->diffInDays($now);

        return [
            'as_of' => $metrics->latestEndDate,
            'period' => $metrics->latestPeriod,
            'revenue_month' => $metrics->latestRevenueMonth,
            'lagging' => $age !== null && $age > (int) $f['lagging_quarter_age_days'],
            'too_old' => $age !== null && $age > (int) $f['max_quarter_age_days'],
        ];
    }

    /**
     * 評級變動。insufficient 與 not_applicable 不在 C < B < B+ 的刻度上，
     * 拿它們比高低沒有意義，一律視為首次評級。
     */
    private function ratingChange(OrderInventoryRating $rating, ?string $previousRating): string
    {
        $previous = $previousRating === null
            ? null
            : OrderInventoryRating::tryFrom($previousRating)?->scaleValue();
        $current = $rating->scaleValue();

        if ($previous === null || $current === null) {
            return 'first';
        }

        return match (true) {
            $current > $previous => 'upgraded',
            $current < $previous => 'downgraded',
            default => 'unchanged',
        };
    }

    /**
     * 升到 A 還缺什麼。框架的 A 級要求六個條件，系統拿不到其中兩類，
     * 因此固定輸出這份**可執行的人工查證清單**，而不是含糊說「資料不足」。
     *
     * @return list<string>
     */
    private function missingForA(): array
    {
        return [
            '查財報附註的存貨組成（原料／在製品／製成品的消長方向）',
            '查公開資訊觀測站的訂單公告與重大訊息',
            '查最近一次法說會簡報的展望與產能規劃',
            '找上下游供應鏈與同業財報交叉驗證',
        ];
    }
}
