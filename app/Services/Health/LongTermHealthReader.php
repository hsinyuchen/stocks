<?php

namespace App\Services\Health;

use App\Data\HealthBlockResult;
use App\Data\HealthInputSnapshot;
use App\Data\LongTermRead;
use App\Enums\AssetType;
use App\Enums\HealthBlock;
use App\Enums\HealthUnavailableReason;
use App\Enums\HealthVerdict;
use App\Enums\MarketRegion;
use App\Support\MarketResolver;

/**
 * 中長線體質：四塊各自判定，**不合成總分**。
 *
 * 純計算：不碰資料庫、網路、快取、LLM，與階段 2 的 OrderInventoryRadar、
 * 階段 4 的 SocialArbitrageClassifier 同一模式——為了能用注入的假輸入
 * 精確測每個分支。輸入全部來自 {@see HealthInputSnapshot}。
 *
 * **不合成總分的理由**：四塊之間不是獨立的（PER、ROE、OCF／淨利都受淨利影響；
 * 成長與 DSO 又共用營收），加權成一個數字會製造一個沒有依據的精確感。
 * 而且所有門檻都未經回測，把它們壓成排名依據等於用一個沒有依據的公式
 * 對使用者宣稱「這檔比那檔好」。
 *
 * **衝突一律回中性，不取平均也不挑一個**（見 {@see combine()}）。
 *
 * **門檻與公式版本讀 config，所以判讀不是只由快照決定**：同一份快照在門檻調整
 * 前後會得到不同的判定，這是刻意的（門檻要能調），也是 formula_version 存在的
 * 理由——保存下來的舊判讀因此說得出自己是哪一版算的。快照負責固定「資料」這一半
 * 輸入，config 是另一半。
 *
 * **版本號只有一個來源**：`config('health.formula_version')`，由本類別在 read()
 * 時讀進 {@see LongTermRead::$formulaVersion}。{@see HealthInputSnapshot} 刻意
 * **不帶**這個欄位——它是公式的屬性不是輸入資料的屬性，放在快照上會讓快照填一個、
 * reader 讀另一個，兩份都被序列化保存，config 一改而快照是舊的就會不一致，
 * 而那正是版本號要防的事。
 */
class LongTermHealthReader
{
    public function read(HealthInputSnapshot $snapshot): LongTermRead
    {
        return new LongTermRead(
            blocks: $snapshot->assetType === AssetType::Stock
                ? [
                    $this->valuation($snapshot),
                    $this->returnOnEquity($snapshot),
                    $this->growth($snapshot),
                    $this->quality($snapshot),
                ]
                : $this->notACompany(),
            formulaVersion: (string) config('health.formula_version'),
        );
    }

    /**
     * ETF 與指數：四塊全部不適用。
     *
     * **不是四塊各自 gate，是整份 gate。** 這四塊量的都是「一家公司體質如何」，
     * 而 ETF 與指數不是公司——沒有 ROE、沒有營收、沒有應收帳款。
     *
     * 少了這道 gate，`0050.TW` 會走完整條路：`MarketResolver::region()` 回台股、
     * 通過 ROE 的市場 gate、`roe` 為 null 於是落到 `NotYet`——而 `NotYet` 的
     * 語意是「等分析或掃描跑過就會有」。ETF 永遠不會有 ROE，那句話是假的。
     *
     * `MarketResolver::assetType()` 自 `bf444b6` 起認得 ETF（台股靠 00 開頭的規則、
     * 美股靠一份明確不完整的清單），資訊由組快照的那一層帶進來。
     *
     * @return list<HealthBlockResult>
     */
    private function notACompany(): array
    {
        return array_map(
            fn (HealthBlock $block): HealthBlockResult => HealthBlockResult::unavailable(
                $block,
                HealthUnavailableReason::NotApplicable,
            ),
            HealthBlock::cases(),
        );
    }

    /**
     * 估值：PER 與 PBR 相對該檔自身歷史的分位。
     *
     * 兩者反向時回中性——見 combine()。只有一個算得出來時照樣判定，
     * 但理由必須寫明「僅依」哪一項，讓使用者知道這一塊只有一半的證據。
     */
    private function valuation(HealthInputSnapshot $snapshot): HealthBlockResult
    {
        $cheap = $this->threshold('valuation.cheap_percentile');
        $expensive = $this->threshold('valuation.expensive_percentile');
        $votes = [];

        foreach (['per' => '本益比', 'pbr' => '股價淨值比'] as $key => $label) {
            $percentile = $snapshot->valuationPercentiles[$key]['percentile'] ?? null;

            if (! is_numeric($percentile)) {
                continue;
            }

            $percentile = (float) $percentile;
            $votes[$label] = [
                'verdict' => match (true) {
                    $percentile <= $cheap => HealthVerdict::Positive,
                    $percentile >= $expensive => HealthVerdict::Negative,
                    default => HealthVerdict::Neutral,
                },
                'text' => sprintf('%s 位於自身歷史第 %.0f 百分位', $label, $percentile),
            ];
        }

        // 樣本不足時 valuationPercentiles() 那一項整個不存在（每檔需 >= 20 列，
        // 而每檔每日只寫一列）。那是「還沒累積」不是「算不出來」。
        if ($votes === []) {
            return HealthBlockResult::unavailable(
                HealthBlock::Valuation,
                HealthUnavailableReason::NotYet,
                $snapshot->fundamentalsAsOf,
            );
        }

        $verdicts = array_column($votes, 'verdict');
        $reasons = array_column($votes, 'text');

        if (count($votes) === 1) {
            $reasons[] = '僅依'.array_key_first($votes).'，另一項樣本不足';
        } elseif ($this->hasBothDirections($verdicts)) {
            $reasons[] = '兩項分位方向相反，無法判斷偏貴或偏便宜';
        }

        return HealthBlockResult::evaluated(
            HealthBlock::Valuation,
            $this->combine($verdicts),
            array_values($reasons),
            $snapshot->fundamentalsAsOf,
        );
    }

    /**
     * ROE。**僅台股**——FinMindFundamentalsProvider 是唯一的**真實**
     * FundamentalsProvider（測試環境綁 FakeFundamentalsProvider），美股從來沒有
     * 這個欄位。所以美股的成因是 NotInUniverse（這個市場沒有這個資料源、
     * 永遠不會有），與台股「還沒抓到」的 NotYet 是兩件事，對使用者是不同的行動。
     *
     * **單位是百分比不是比率**：FinMind 那條算式是
     * `$ttmNet / $latestEquity * 100`，實測 DB 裡最大值 50.89（＝50.89%）。
     * config 的門檻因此是 5.0／15.0 而不是 0.05／0.15，理由的 sprintf 也不再乘 100。
     */
    private function returnOnEquity(HealthInputSnapshot $snapshot): HealthBlockResult
    {
        if (MarketResolver::region($snapshot->symbol) !== MarketRegion::Taiwan) {
            return HealthBlockResult::unavailable(
                HealthBlock::ReturnOnEquity,
                HealthUnavailableReason::NotInUniverse,
            );
        }

        $roe = $snapshot->fundamentals?->roe;

        if (! is_numeric($roe)) {
            return HealthBlockResult::unavailable(
                HealthBlock::ReturnOnEquity,
                HealthUnavailableReason::NotYet,
                $snapshot->fundamentalsAsOf,
            );
        }

        $roe = (float) $roe;

        return HealthBlockResult::evaluated(
            HealthBlock::ReturnOnEquity,
            $this->band($roe, $this->threshold('roe.weak'), $this->threshold('roe.strong')),
            // ROE 已經是百分比（FinMindFundamentalsProvider 回的是
            // $ttmNet / $latestEquity * 100），**不要再乘 100**。
            [sprintf('股東權益報酬率 %.1f%%', $roe)],
            $snapshot->fundamentalsAsOf,
        );
    }

    /**
     * 成長：營收年增（台股月營收、美股季營收，基準由 OrderInventoryMetrics 決定）。
     *
     * **用 `OrderInventoryMetrics::$revenueYoy`（比率，`$current / $base - 1`），
     * 不要誤用 `FundamentalsData::$revenueYoy`（百分比）。** 兩者同名、差 100 倍，
     * 是本階段最容易接錯的一個欄位。
     *
     * 序列不存在是 NotYet（跑過分析就會有）；序列在但年增算不出來是 Indeterminate
     * （缺去年同期，再跑幾次也不會變出來）。兩者對使用者是不同的行動。
     */
    private function growth(HealthInputSnapshot $snapshot): HealthBlockResult
    {
        if ($snapshot->metrics === null) {
            return HealthBlockResult::unavailable(HealthBlock::Growth, HealthUnavailableReason::NotYet);
        }

        $yoy = $snapshot->metrics->revenueYoy;

        if (! is_numeric($yoy)) {
            return HealthBlockResult::unavailable(
                HealthBlock::Growth,
                HealthUnavailableReason::Indeterminate,
                $snapshot->financialPeriod,
            );
        }

        $yoy = (float) $yoy;

        return HealthBlockResult::evaluated(
            HealthBlock::Growth,
            $this->band($yoy, $this->threshold('growth.weak'), $this->threshold('growth.strong')),
            [sprintf('營收年增 %+.1f%%', $yoy * 100)],
            $snapshot->financialPeriod,
        );
    }

    /**
     * 財務品質：OCF／淨利，以及應收帳款週轉天數的變化。
     *
     * **OCF 為負時比率不算數。** 淨利同為負會讓 OCF／淨利變成正數，看起來反而
     * 健康——OrderInventoryMetrics 的 docblock 已寫明這個陷阱，OrderInventoryRadar
     * 的 C8 也已經在讀比率之前先看 operatingCashFlowNegative。這裡走同一條短路。
     *
     * **用 DSO 不用合成 CCC 變化。** OrderInventoryMetrics 有 ccc 與三個 delta，
     * 合成 dio + dso - dpo 的變化是做得到的，但 DPO 的分母是 COGS——
     * **階段 2 抓過一個真實的假宣稱就出在這裡**：COGS 下滑會讓 DPO 上升而
     * 應付帳款根本沒動。合成 CCC 變化會繼承那個脆弱性，讓一家 COGS 下滑的
     * 公司顯示「現金循環改善」。DSO 直接對應規格寫的「應收品質」，
     * 只需一個非 null 欄位，也沒有那個分母問題。
     *
     * **產業適用性問既有的 OrderInventoryIndustryPolicy**（透過 snapshot 帶進來的
     * industryBucket），不在這裡另立一套：CCC／DSO 對金融、證券、銀行、航運、
     * 觀光餐旅等服務業沒有意義，而那份名單已經存在。同一件事兩份判準遲早漂移。
     */
    private function quality(HealthInputSnapshot $snapshot): HealthBlockResult
    {
        if ($snapshot->industryBucket === 'not_applicable') {
            return HealthBlockResult::unavailable(
                HealthBlock::Quality,
                HealthUnavailableReason::NotApplicable,
                $snapshot->financialPeriod,
            );
        }

        if ($snapshot->metrics === null) {
            return HealthBlockResult::unavailable(HealthBlock::Quality, HealthUnavailableReason::NotYet);
        }

        $votes = [];
        $ocf = $snapshot->metrics->ocfToNetIncome;

        // OCF 為負一律負面，**在看比率之前短路**：淨利同為負時比率會變成正數，
        // 只讀比率會把「虧損且營運現金流出」講成「營業現金流為淨利的 1.25 倍」。
        // 形狀照抄 OrderInventoryRadar 的 C8，不另立第二套判準。
        if ($snapshot->metrics->operatingCashFlowNegative) {
            $votes['營業現金流／淨利'] = [
                'verdict' => HealthVerdict::Negative,
                'text' => is_numeric($ocf) && (float) $ocf > 0.0
                    ? sprintf('營業現金流為負，比率之所以是 %.2f 倍是因為淨利同為負，不代表現金流健康', (float) $ocf)
                    : '營業現金流為負，本期營運現金流出',
            ];
        } elseif (is_numeric($ocf)) {
            $votes['營業現金流／淨利'] = [
                'verdict' => $this->band(
                    (float) $ocf,
                    $this->threshold('quality.ocf_to_net_income_weak'),
                    $this->threshold('quality.ocf_to_net_income_strong'),
                ),
                'text' => sprintf('營業現金流為淨利的 %.2f 倍', (float) $ocf),
            ];
        }

        $dso = $snapshot->metrics->dsoChangeDays;

        if (is_numeric($dso)) {
            $dso = (float) $dso;
            $votes['應收天數變化'] = [
                // 天數變多＝收款變慢＝較差，方向與其他指標相反，所以不共用 band()。
                'verdict' => match (true) {
                    $dso <= $this->threshold('quality.dso_change_days_better') => HealthVerdict::Positive,
                    $dso >= $this->threshold('quality.dso_change_days_worse') => HealthVerdict::Negative,
                    default => HealthVerdict::Neutral,
                },
                'text' => sprintf('應收帳款週轉天數較上期 %+.1f 天', $dso),
            ];
        }

        if ($votes === []) {
            return HealthBlockResult::unavailable(
                HealthBlock::Quality,
                HealthUnavailableReason::Indeterminate,
                $snapshot->financialPeriod,
            );
        }

        $verdicts = array_column($votes, 'verdict');
        $reasons = array_column($votes, 'text');

        if (count($votes) === 1) {
            $reasons[] = '僅依'.array_key_first($votes).'，另一項算不出來';
        }

        return HealthBlockResult::evaluated(
            HealthBlock::Quality,
            $this->combine($verdicts),
            array_values($reasons),
            $snapshot->financialPeriod,
        );
    }

    /**
     * 三態分帶。**低於 weak 才是負面**——恰好等於 weak 是中性。
     *
     * 「沒達到正面門檻」不等於「負面證據」，這條分帶就是為了讓那句話成立。
     */
    private function band(float $value, float $weak, float $strong): HealthVerdict
    {
        return match (true) {
            $value >= $strong => HealthVerdict::Positive,
            $value < $weak => HealthVerdict::Negative,
            default => HealthVerdict::Neutral,
        };
    }

    /**
     * 合併多票。**同時有正有負一律回中性**——不取平均、不挑一個。
     *
     * 證據互相矛盾時「看不出偏向」才是實話；取平均會把兩個相反的訊號變成
     * 一個看似溫和的結論，而那個結論沒有任何一項證據支持。
     *
     * 一正一中性回正：那不是矛盾，是一項有訊號、一項沒訊號。
     *
     * @param  list<HealthVerdict>  $verdicts
     */
    private function combine(array $verdicts): HealthVerdict
    {
        if ($this->hasBothDirections($verdicts)) {
            return HealthVerdict::Neutral;
        }

        if (in_array(HealthVerdict::Positive, $verdicts, true)) {
            return HealthVerdict::Positive;
        }

        return in_array(HealthVerdict::Negative, $verdicts, true)
            ? HealthVerdict::Negative
            : HealthVerdict::Neutral;
    }

    /** @param list<HealthVerdict> $verdicts */
    private function hasBothDirections(array $verdicts): bool
    {
        return in_array(HealthVerdict::Positive, $verdicts, true)
            && in_array(HealthVerdict::Negative, $verdicts, true);
    }

    /**
     * 嚴格取值。裸 `(float) config(...)` 缺鍵時會靜默變 0，而這些門檻的 0
     * 會讓判定無聲地全部偏向某一邊，且沒有任何錯誤訊號可供察覺。
     */
    private function threshold(string $key): float
    {
        $value = config("health.{$key}");

        if (! is_numeric($value)) {
            throw new \RuntimeException("health.{$key} config 缺失或非數值。");
        }

        return (float) $value;
    }
}
