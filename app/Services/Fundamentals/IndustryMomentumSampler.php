<?php

namespace App\Services\Fundamentals;

use App\Data\IndustryMomentum;
use App\Data\OrderInventoryAssessment;
use App\Data\OrderInventoryData;
use App\Enums\IndustryMomentumUnavailableReason;
use App\Models\Instrument;
use Carbon\CarbonImmutable;

/**
 * 產業動能取樣（**台股限定**）：同產業最新月營收 YoY 的中位數，以及標的的超額。
 *
 * 與 {@see OrderInventoryPeerSampler} 的差別只在比的數值——那個比**季**營收年增
 * （餵 C10），這個比**月**營收 YoY。掃描邏輯全在共用基底
 * {@see OrderInventoryIndustrySampler}。
 *
 * 非台股一律回 `applicable = false` 而**不是**回 0 檔樣本：美股沒有月營收
 * （SEC 不提供）、industry 也恆為 null（階段 1 決定不抓 SIC）。「這個市場沒有
 * 這個功能」與「有功能但還沒樣本」語意不同，呈現層必須分開講。
 *
 * 綁定為 scoped，理由見基底 docblock。
 */
class IndustryMomentumSampler extends OrderInventoryIndustrySampler
{
    public function __construct(private readonly FundamentalsService $fundamentals) {}

    /**
     * 只讀入口：industry 自己從快取取，呼叫端不必先弄到一份 OrderInventoryData。
     *
     * 為什麼需要它：`forInstrument()` 要 industry，而 industry 只存在於
     * `fundamentals.order_inventory` 這份快照裡。呼叫端手上通常只有一份
     * {@see OrderInventoryAssessment}，那上面只有 `industryBucket`
     * （評級用的粗分類桶）而**沒有**原始 `industry_category`——拿桶去餵是另一種
     * 東西，比出來的同業根本不同群。
     *
     * 為什麼是**只讀**：委派給 {@see FundamentalsService::cachedOrderInventoryFor()}，
     * 那個方法只讀「帶序列的最新一列」，過期就回 null 而不去抓。真正會抓的入口是
     * `orderInventoryFor()`：台股走 FinMind、美股打 SEC EDGAR（timeout 40 秒、沒有
     * FinMindGate 那種斷路器）。本方法的消費端之一是 AlertEvaluator，它跑在首頁的
     * 同步 web 請求裡，而 PHP 的 max_execution_time 不是例外、try/catch 攔不到，
     * 那條路徑也沒有掃描預算或 job timeout 可以兜底。
     * 拿不到就當產業未知（`IndustryUnknown`），等下一次個股分析／選股掃描把序列
     * 抓進快取即可。命名沿用階段 3 立下的「cachedFor = 只讀入口」慣例。
     */
    public function cachedFor(Instrument $subject): IndustryMomentum
    {
        return $this->forInstrument($subject, $this->fundamentals->cachedOrderInventoryFor($subject)?->industry);
    }

    public function forInstrument(Instrument $subject, ?string $industry): IndustryMomentum
    {
        if ($this->marketOf($subject->symbol) !== 'tw') {
            return IndustryMomentum::notApplicable(IndustryMomentumUnavailableReason::NotTaiwan);
        }

        if ($industry === null || $industry === '') {
            return IndustryMomentum::notApplicable(IndustryMomentumUnavailableReason::IndustryUnknown);
        }

        $metrics = $this->metricsForIndustry($subject, $industry);
        ['median' => $median, 'samples' => $samples] = $this->medianOfPeers($metrics, $subject);

        // 標的自身的 YoY 優先取自同一次掃描，掃不到才點查補一次（理由見基底的
        // metricForSubject()：掃描的記憶體上限會在大產業裡把標的自己截掉）。
        // 仍可能是 null——尚未快取、過舊、產業不符——那時 excess 也是 null，
        // 不得以 0 代替。
        $own = $this->metricForSubject($subject, $industry, $metrics);

        return new IndustryMomentum(
            applicable: true,
            industry: $industry,
            median: $median,
            own: $own,
            // 超額只在兩邊都算得出來時成立。用 0 代替會把「無從比較」講成「與產業同步」。
            excess: $median === null || $own === null ? null : $own - $median,
            samples: $samples,
        );
    }

    protected function configPrefix(): string
    {
        return 'order_inventory.industry_momentum';
    }

    /**
     * 最新一個月的月營收 YoY。
     *
     * 以**月份鍵**取最新而不是取陣列最後一筆：序列雖然約定舊→新，但那是上游的
     * 約定而非這裡能驗證的事實，階段 1 已踩過「用陣列位置而非月份配對 YoY」的坑。
     * 依 month 排序後取末筆，順序錯亂的快取列也不會比到錯的月份。
     *
     * 最新月沒有 YoY（新上市、抓取視窗內沒有去年同月）時回 null 讓基底略過該檔，
     * **不得回 0**：0 是合法的 YoY，用它代表「算不出來」會把中位數往下拉。
     */
    protected function metricFor(OrderInventoryData $data): ?float
    {
        $byMonth = [];

        foreach ($data->monthlyRevenue as $row) {
            $month = $row['month'] ?? null;

            if (! is_string($month) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $month, $m)) {
                continue;
            }

            // regex 只驗形狀不驗日曆有效性：'2026-13-01' 會讓 Carbon::parse() 拋例外，
            // '0000-00-00' 會被靜默解成別的日期。checkdate() 先擋掉兩者。
            if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                continue;
            }

            $byMonth[CarbonImmutable::parse($month)->format('Y-m-01')] = $row['yoy'] ?? null;
        }

        if ($byMonth === []) {
            return null;
        }

        ksort($byMonth);

        $yoy = $byMonth[array_key_last($byMonth)];

        return is_numeric($yoy) ? (float) $yoy : null;
    }
}
