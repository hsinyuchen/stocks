<?php

namespace App\Services\Fundamentals;

use App\Data\IndustryMomentum;
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
