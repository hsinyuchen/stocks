<?php

namespace App\Services\Screener\Rules;

use App\Data\SocialArbitrage;
use App\Enums\SocialArbitrageStage;
use App\Services\Screener\ScreenRule;
use App\Services\Screener\ScreenRuleNote;
use App\Services\Social\SocialArbitrageClassifier;

/**
 * 找「新聞熱度已升溫、股價尚未反映、法人也還沒明顯買」的標的。
 *
 * 判定只取 {@see SocialArbitrage::$stage}，**不重新拆解四條腿**：分類是
 * {@see SocialArbitrageClassifier} 串聯後的單一結論，
 * 在這裡重算等於複製一份必然隨門檻漂移的副本（同 SocialArbitrage docblock
 * 對呈現層立的規矩）。
 *
 * 只認 {@see SocialArbitrageStage::Early}。其餘四桶都是**明確的非早期結論**，
 * 不是「還沒分出來」——已部分反映與已高度反映代表市場已經動了，假訊號代表
 * 基本面反證，資料不足代表連熱度樣本都還不夠。把它們任何一個算成命中，都會
 * 讓這條規則的名字與它篩出來的東西對不上。
 */
final class EarlySocialArbitrage implements ScreenRule, ScreenRuleNote
{
    public function key(): string
    {
        return 'early_social_arbitrage';
    }

    public function label(): string
    {
        return '早期社交套利';
    }

    /**
     * 「社交」兩個字會讓使用者以為涵蓋 YouTube／X／Reddit／PTT 等來源，
     * 本平台一個都沒有接入。門檻同樣未經回測。兩則都不得省略。
     *
     * @return non-empty-list<string>
     */
    public function noteKeys(): array
    {
        return ['screener.noteSocialCoverage', 'screener.noteSocialNoBacktest'];
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [ScreenRule::NEEDS_SOCIAL];
    }

    /**
     * 缺鍵或型別不對一律不命中。
     *
     * 沿用 FundamentalRule／OrderInventoryRule 的既有決定：不得當成無條件通過，
     * 否則沒有資料的標的會混進結果。注意「資料不足」不走這條——分類器最差也回
     * 一份 stage 為 Insufficient 的完整 DTO，由下面的等值比較擋掉。
     */
    public function matches(array $series, array $context = []): bool
    {
        $payload = $context[ScreenRule::NEEDS_SOCIAL] ?? null;

        if (! $payload instanceof SocialArbitrage) {
            return false;
        }

        return $payload->stage === SocialArbitrageStage::Early;
    }

    /**
     * 社交套利不支援歷史回放。
     *
     * 兩個各自獨立的原因：`news_items` 只保留 `config('news.retention_days')`（90 天），
     * 回測區間多半早於第一筆還留著的新聞；而熱度百分位需要至少 3 段、即
     * `heat_window_days * 3 - 1 = 41` 天的歷史才算得出來。用當下的熱度快照回放過去
     * 是前視偏誤，因此一律回 false。
     */
    public function matchesAt(array $series, int $n, array $context = []): bool
    {
        return false;
    }
}
