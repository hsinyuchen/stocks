<?php

namespace App\Services\Health;

use App\Data\HealthInputSnapshot;
use App\Data\ShortTermRead;
use App\Enums\HealthUnavailableReason;
use App\Services\SignalEngine;

/**
 * 短線體質：技術與籌碼**兩個維度**，外加背離旗標。**不合成總分。**
 *
 * 純計算：不碰資料庫、網路、快取、LLM，與 {@see LongTermHealthReader} 同一模式。
 * 建構子只收 {@see SignalEngine}（它本身也是純計算），輸入全部來自
 * {@see HealthInputSnapshot}——這是「同一份快照必產出相同判讀」這個不變式的前提，
 * 注入任何會碰 IO 的東西都會讓它悄悄失效。
 *
 * **不自己重算技術與籌碼立場。** 判定規則已經在 SignalEngine 裡，而它被警報、
 * 儀表板與既存的 `stock_analyses.rule_signal` 共用；在這裡另寫一套，個股頁與
 * 個股分析對同一檔會給出兩個不同的立場，而使用者無從得知哪一個算數。
 * 本類別做的是**翻譯**：把那個為別的用途長出來的陣列（帶著本階段用不到的
 * 融資與維度區塊）收斂成一個窄的 DTO，並把「資料不足」從字串轉成 null。
 *
 * **唯一的判定是新鮮度 gate**，見 {@see gatedTechnicalStance()}：價格太舊時把
 * SignalEngine 算出來的技術立場丟掉，改報不可評估。這不改 SignalEngine 的規則
 * ——gate 在它外面，判定本身一個字都沒動。門檻與量測依據在
 * `config/health.php` 的 technical 區塊，「今天是哪一天」由快照帶進來
 * （本類別零 IO，不可以自己叫 `now()`）。
 *
 * **籌碼面刻意不 gate**，理由見 {@see ShortTermRead}：籌碼立場的持續性沒有量過，
 * 套一個沒有量測依據的門檻違反本專案的紀律。籌碼的年齡照樣輸出讓使用者自己看。
 */
class ShortTermHealthReader
{
    public function __construct(private readonly SignalEngine $signals) {}

    public function read(HealthInputSnapshot $snapshot): ShortTermRead
    {
        $signal = $this->signals->evaluate($snapshot->indicators, $snapshot->chipFlows);

        $technicalStance = $this->gatedTechnicalStance($signal, $snapshot);
        $chip = is_array($signal['chip'] ?? null) ? $signal['chip'] : null;

        return new ShortTermRead(
            technicalStance: $technicalStance,
            chipStance: $chip === null ? null : (string) $chip['stance'],
            // 'none' 是 SignalEngine 用字串表示的「無法判定」（SignalFieldGuide
            // 自己這樣定義），同樣轉成 null。壓成 bool 會讓它與 'confirm' 併成
            // 同一格，等於對沒有籌碼資料的標的宣稱「技術與籌碼一致」。
            //
            // 技術立場被 gate 掉時 alignment 必須一併作廢：SignalEngine 是拿被丟掉
            // 的那個立場算出 confirm／diverge 的，留著等於用一個剛宣告不可評估的
            // 結論去斷言「技術與籌碼一致」。
            alignment: $technicalStance === null ? null : $this->alignment($signal['alignment'] ?? 'none'),
            // 立場不成立時理由也不成立：那些句子講的是被丟掉的那個立場為什麼成立。
            // 中長線四塊在 unavailable 時 reasons 同樣是空陣列（見 HealthBlockResult）。
            technicalReasons: $technicalStance === null ? [] : array_values($signal['reasons'] ?? []),
            chipReasons: array_values($chip['reasons'] ?? []),
            rsi: $this->float($snapshot->indicators['rsi'] ?? null),
            volumeRatio: $this->volumeRatio($snapshot),
            priceAsOf: $snapshot->priceAsOf,
            chipAsOf: $snapshot->chipAsOf,
            technicalUnavailableReason: $this->technicalUnavailableReason($signal, $snapshot),
            priceAgeTradingDays: $snapshot->priceAgeTradingDays,
            chipAgeTradingDays: $snapshot->chipAgeTradingDays,
        );
    }

    /**
     * 技術立場，套過新鮮度 gate。不可評估一律回 null（成因另由
     * {@see technicalUnavailableReason()} 給），與中長線四塊同一語意。
     *
     * 'insufficient_data' 是 SignalEngine 用字串表示的「不可評估」，在判讀層一律
     * 轉成 null，呈現層才只需處理一種缺席。
     *
     * @param  array<string, mixed>  $signal
     */
    private function gatedTechnicalStance(array $signal, HealthInputSnapshot $snapshot): ?string
    {
        if ($snapshot->priceStale) {
            return null;
        }

        $stance = (string) ($signal['stance'] ?? 'insufficient_data');

        return $stance === 'insufficient_data' ? null : $stance;
    }

    /**
     * 技術面不可評估的成因，null 代表算得出來。
     *
     * **gate 優先於 `insufficient_data`**（先看 `priceStale`）：兩者同時成立時報
     * `Stale`，那是使用者能採取行動的那一個。K 棒不足有很大一部分正是因為價格根本
     * 沒在更新，回 `NotYet`＝「等分析或掃描再跑幾次就會有」會指向一個不會解決問題
     * 的動作。順序反過來，價格停了 30 天又只有 3 根 K 棒的標的會被叫去重跑分析。
     *
     * @param  array<string, mixed>  $signal
     */
    private function technicalUnavailableReason(array $signal, HealthInputSnapshot $snapshot): ?HealthUnavailableReason
    {
        if ($snapshot->priceStale) {
            return HealthUnavailableReason::Stale;
        }

        return ($signal['stance'] ?? 'insufficient_data') === 'insufficient_data'
            ? HealthUnavailableReason::NotYet
            : null;
    }

    /**
     * SignalEngine 的三態 alignment；`none`（無法判定）轉成 null。
     *
     * 未知的鍵一律當成無法判定：多一個未知狀態時，寧可少講一句，也不要把它
     * 當成「同向」而對使用者宣稱一件沒有依據的事。
     */
    private function alignment(mixed $alignment): ?string
    {
        return in_array($alignment, ['confirm', 'diverge'], true) ? $alignment : null;
    }

    /**
     * 最新成交量相對近 20 日均量的倍數。
     *
     * **脈絡不是判定依據**（見 {@see ShortTermRead}）：它與 KD／MACD／均線同為
     * 價格動能的衍生量，讓它投票只是讓同一段趨勢被多數一次。這裡算出來是為了
     * 讓使用者看得到「今天的量是不是異常」，不進入任何門檻比較。
     */
    private function volumeRatio(HealthInputSnapshot $snapshot): ?float
    {
        $volume = $snapshot->indicators['volume'] ?? null;
        $average = $snapshot->indicators['volume_ma20'] ?? null;

        if (! is_numeric($volume) || ! is_numeric($average) || (float) $average <= 0.0) {
            return null;
        }

        return round((float) $volume / (float) $average, 2);
    }

    /**
     * 暖身期的指標是 null 不是 0。裸 `(float)` 轉型會把「還算不出來」變成
     * 一個具體數值，而 0 在 RSI 的量尺上是極度超賣，語意完全相反。
     */
    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
