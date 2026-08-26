<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryAssessment;
use App\Data\OrderInventoryData;
use App\Data\OrderInventoryMetrics;
use App\Enums\OrderInventoryRating;
use App\Enums\RevenueUnknownReason;
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
     * 序列過期時會就地打一次上游（美股是 SEC EDGAR，timeout 40 秒）。跑在同步
     * web 請求裡的呼叫端請改用 cachedFor()。
     *
     * **回 null 只代表「拿不到序列」**（非台美市場、序列從未落地、抓取失敗），
     * 不代表「產業不適用或資料不足」——那兩種情況都會回一份完整的 assessment，
     * rating 分別是 not_applicable 與 insufficient。
     *
     * @return array{assessment: OrderInventoryAssessment, peer_samples: int}|null
     */
    public function forInstrument(Instrument $instrument): ?array
    {
        return $this->rate($instrument, $this->fundamentals->orderInventoryFor($instrument));
    }

    /**
     * 只讀快取的評級：序列已經在 DB 且未過期時才評級，否則回 null，**一次上游都不打**。
     *
     * 為警報評估而存在：它跑在首頁的同步 web 請求裡（DashboardController 刻意把
     * evaluate() 放在 session cache 之外，觸發要即時反映），而 forInstrument() 在
     * TTL 過期時會就地抓一次上游——美股那條打 SEC EDGAR、timeout 40 秒、沒有
     * FinMindGate 那種斷路器，受限主機的 max_execution_time 會先把請求砍掉，而
     * PHP 的執行時間上限不是例外，呼叫端的 try/catch 攔不到。
     * 選股掃描與快報 job 有各自的總量預算（scan_time_budget_seconds／job timeout），
     * 這條同步路徑一個都沒有，只能從「不抓」這一端解。
     *
     * 拿不到就當沒有：該檔不命中訂單庫存類規則，等下一次個股分析／選股掃描把序列
     * 抓進快取即可，不在使用者開首頁的當下替他等一次上游。
     *
     * @return array{assessment: OrderInventoryAssessment, peer_samples: int}|null
     */
    public function cachedFor(Instrument $instrument): ?array
    {
        return $this->rate($instrument, $this->fundamentals->cachedOrderInventoryFor($instrument));
    }

    /**
     * 社交套利需要的兩個序列訊號，**只讀、不寫、不取樣、一次上游都不打**。
     *
     * 與 cachedFor() 的差別在新鮮度那把尺：這裡走
     * {@see FundamentalsService::orderInventorySeriesFor()}（序列自己的 30 天視窗），
     * cachedFor() 走 cachedOrderInventoryFor()（估值的每日 TTL）。後者對評級是對的
     * ——「回 null」等價於「正常路徑此刻會去抓上游」——但拿來量季財報＋月營收會讓
     * 昨天抓的序列在今天盤後憑空消失，理由見那個方法的 docblock。
     *
     * **刻意不回一份 `OrderInventoryAssessment`**：這條路徑不做同業取樣（peer
     * median 為 null，C10 因此恆為 null），算出來的 `rating` 會與其他消費端看到的
     * 不一樣。回一份看起來完整、實際上是另一套輸入算出來的 assessment，等於發一個
     * 隨時會被誤用的陷阱。C1 是 revenueStreakMet、毛利率 QoQ 在 metrics 上，兩者
     * 都不依賴同業中位數，所以窄回傳沒有任何損失。
     *
     * **不得呼叫 persistRating()**：本方法的消費端（社交套利）宣稱全程只讀，而它
     * 跑在個股頁每次開頁、選股器每掃一檔、首頁每次載入的路徑上。這裡算出的評級
     * 缺同業腿、也不屬於任何一次完整評級，寫回去只會污染評級軌跡。
     *
     * 拿不到序列時三個值分別是 null／null／NotYet，不得以 false／0 頂替。
     *
     * **`revenue_unknown_reason` 說明 C1 為什麼沒有結論。** 一個布林
     * （「適不適用」）只分得出兩態，而實測有四種成因落在這個入口，
     * 對使用者是四種不同的行動——逐條見 {@see RevenueUnknownReason}。
     * 尤其「序列完整落地但季末日太舊」：序列**累積過了**，再跑一百次掃描
     * 季末日也不會往前走，講成「尚未累積」是把使用者留在一個不會結束的等待裡。
     *
     * 序列拿不到時回 **NotYet** 而不是 NotApplicable：那時還不知道產業是什麼，
     * 而「不適用」是一個需要證據才能下的結論。在沒讀到產業別的情況下宣稱本框架
     * 不適用，正是本框架一路在避免的過度宣稱。
     *
     * 產業不適用**優先於**資料過舊：`assess()` 的串聯 0（過舊／缺科目）排在
     * 串聯 1（產業不適用）之前，所以一檔過舊的航運股拿到的 rating 是
     * insufficient；但對使用者而言「這個產業永遠不會有答案」蓋過「這份資料太舊」
     * ——補了新財報也還是不會有答案。因此這裡看 `industryBucket` 而不是 rating。
     *
     * **`metrics` 與 `industry_bucket` 是純量表與產業別，不是評級。** 它們不依賴
     * 同業中位數（那是 C10 的輸入），所以窄回傳裡多帶這兩項不會重蹈上一段警告的
     * 覆轍——被排除的是「看起來完整、實際上少一條腿」的 `rating`，不是量表本身。
     * 兩者都已經在下面那次 `assess()` 裡算完了，多帶不需要任何額外的查詢或計算；
     * 體質判讀的成長與品質兩塊要的正是它們，另開一條只讀路徑去重算才是分岔的開始。
     *
     * @return array{revenue_verified: ?bool, gross_margin_qoq_pp: ?float, revenue_unknown_reason: ?RevenueUnknownReason, metrics: ?OrderInventoryMetrics, industry_bucket: ?string}
     */
    public function seriesSignalsFor(Instrument $instrument): array
    {
        $data = $this->fundamentals->orderInventorySeriesFor($instrument);

        if ($data === null || ! $data->hasAny()) {
            return [
                'revenue_verified' => null,
                'gross_margin_qoq_pp' => null,
                'revenue_unknown_reason' => RevenueUnknownReason::NotYet,
                // 序列拿不到時產業別也是未知，不得以 'unknown' 以外的值頂替——
                // 「不適用」是一個需要證據才能下的結論。
                'metrics' => null,
                'industry_bucket' => null,
                // 序列拿不到時談不上「太舊」，那是「還沒有」。
                'series_too_old' => false,
            ];
        }

        // 走完整的 assess() 而不是直接呼叫 conditions()：資料過舊／產業不適用時
        // assess() 會短路，條件表整個空掉（C1 於是為 null）。跳過那兩道短路等於
        // 用一份已被判定不可評級的序列去宣稱「營收已驗證」。
        $assessment = $this->radar->assess($data);
        $verified = $assessment->conditions['C1'] ?? null;

        return [
            'revenue_verified' => $verified,
            'gross_margin_qoq_pp' => $assessment->metrics->grossMarginQoqPp,
            'revenue_unknown_reason' => $verified === null ? $this->unknownReason($assessment) : null,
            'metrics' => $assessment->metrics,
            'industry_bucket' => $assessment->industryBucket,
            // **時效旗標必須跟 metrics 一起出去。** assess() 在季末日超過
            // order_inventory.freshness.max_quarter_age_days 時短路成 Insufficient，
            // 但這裡照樣回傳算好的 metrics；消費端看不到 assessment 的 freshness，
            // 少了這個鍵就會拿一份已被判定過舊的序列去下結論——同一份資料在營收那邊
            // 叫 RevenueUnknownReason::Stale、在體質判讀那邊卻變成「成長：正面」。
            'series_too_old' => (bool) ($assessment->freshness['too_old'] ?? false),
        ];
    }

    /**
     * C1 沒有結論時，是哪一種沒有結論。
     */
    private function unknownReason(OrderInventoryAssessment $assessment): RevenueUnknownReason
    {
        if ($assessment->industryBucket === 'not_applicable') {
            return RevenueUnknownReason::NotApplicable;
        }

        if ($assessment->rating === OrderInventoryRating::Insufficient) {
            return RevenueUnknownReason::Stale;
        }

        // 走到這裡代表序列可評級、產業也適用，只是 C1 本身算不出來
        // （既無月營收、序列裡也沒有去年同季）。
        return RevenueUnknownReason::Indeterminate;
    }

    /**
     * 取得序列之後的共同流程：同業取樣、前次評級、評級、寫回。
     *
     * 兩個入口的差別**只在序列從哪裡來**，評級與寫回一律相同——警報路徑同樣要
     * 寫回評級，否則首頁評出來的結果不會進到評級軌跡，ratingChange 會與其他
     * 入口看到的不一致。
     *
     * @return array{assessment: OrderInventoryAssessment, peer_samples: int}|null
     */
    private function rate(Instrument $instrument, ?OrderInventoryData $data): ?array
    {
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
     * 呼叫來源共五處，寫入頻率差距很大，改動這裡前先看齊全：個股分析、個股問答
     * （**每一則訊息**）、選股掃描（每檔）、排程快報（每檔），以及首頁的警報評估
     * （**每次首頁載入**，走 cachedFor()）。最後一項是同步 web 路徑，任何在這裡
     * 加上的額外查詢或寫入都會直接落在使用者的開頁延遲上。
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
