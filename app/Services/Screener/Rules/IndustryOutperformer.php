<?php

namespace App\Services\Screener\Rules;

use App\Data\IndustryMomentum;
use App\Services\Screener\ScreenRule;

/**
 * 找「所處產業正在加速，且自己還跑贏產業」的標的（僅台股，見 IndustryMomentum）。
 *
 * 三個條件缺一不可，理由各自不同：
 *
 * - `applicable`：非台股與產業未知都沒有「同業」可言，帶著的數字一律是 null，
 *   但把不適用的標的放行等於宣稱它符合一個從未評估過的條件。
 * - 產業中位數達 `industry_accelerating`：只看超額的話，一檔在衰退產業裡「跌得
 *   比較少」的標的也會被篩出來——那不是這條規則要找的東西。
 * - 超額達 `outperformance`：只看產業的話，篩出來的是整個產業，而不是產業裡
 *   值得看的那幾檔。
 *
 * `median` 或 `excess` 為 null 是「算不出來」（樣本不足、自身 YoY 缺基期），
 * 一律不命中——不是命中、也不是例外。`null >= 0.1` 在 PHP 會靜默成立，所以
 * 必須先擋 null 再比較。
 */
final class IndustryOutperformer implements ScreenRule
{
    public function key(): string
    {
        return 'industry_outperformer';
    }

    public function label(): string
    {
        return '產業加速且個股跑贏';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [ScreenRule::NEEDS_INDUSTRY_MOMENTUM];
    }

    /**
     * 缺鍵或型別不對一律不命中（同 FundamentalRule／OrderInventoryRule 的既有決定）。
     *
     * 「不適用」與「樣本不足」不走這條：兩者都會回一份完整的 IndustryMomentum，
     * 由下面的條件判定擋掉。
     */
    public function matches(array $series, array $context = []): bool
    {
        $momentum = $context[ScreenRule::NEEDS_INDUSTRY_MOMENTUM] ?? null;

        if (! $momentum instanceof IndustryMomentum) {
            return false;
        }

        $accelerating = $this->threshold('industry_accelerating');
        $outperformance = $this->threshold('outperformance');

        if ($accelerating === null || $outperformance === null) {
            return false;
        }

        return $momentum->applicable === true
            && $momentum->median !== null
            && $momentum->median >= $accelerating
            && $momentum->excess !== null
            && $momentum->excess >= $outperformance;
    }

    /**
     * 產業動能不支援歷史回放。
     *
     * 與訂單庫存那段的理由**不同**：`fundamentals.order_inventory` 每檔只保留最新
     * 一列，所以「歷史上某一天的產業中位數」根本沒有被保存過。這不是「歷史深度
     * 還不夠、等累積夠了就能開放」，而是從未存在——要支援回放得先改成逐月落地
     * 產業快照，那是另一個功能。
     */
    public function matchesAt(array $series, int $n, array $context = []): bool
    {
        return false;
    }

    /**
     * 門檻一律從 config 取，**取不到就回 null 讓 matches() 不命中**。
     *
     * 三種寫法都不行，這是第三種：
     *
     * - 裸 `(float)` 轉型：`(float) null === 0.0` 會讓「任何非負中位數都算產業
     *   加速」「任何非負超額都算跑贏」，把一條被靜默放寬的判準包裝成命中，
     *   而且沒有任何錯誤訊號（同 SocialArbitrageClassifier::requireFloat() 的顧慮）。
     * - 拋例外：本規則的 matches() 在 AlertEvaluator 的訊號路徑上**沒有**被
     *   try/catch 包住（contextFor() 的 catch 只蓋 context 載入那一段，
     *   matchesSignal() 本身沒有），拋錯會讓首頁整個 500。
     * - 退回類別裡硬寫的預設值：不會放寬判準，但會**靜默分歧**——有人改了 config
     *   鍵名或把值設成無效，規則照舊用類別裡那個數字跑，config 與實際判準從此
     *   不一致而無從察覺。門檻的唯一真相應該只有 config 一處。
     *
     * 回 null 則三者皆免：不命中是這條規則對「沒有資料」的既有反應（見 matches()
     * 對 context 形狀的處理），對使用者的效果是少一檔候選而不是多一檔假候選。
     */
    private function threshold(string $key): ?float
    {
        $value = config("order_inventory.industry_momentum.{$key}");

        return is_numeric($value) ? (float) $value : null;
    }
}
