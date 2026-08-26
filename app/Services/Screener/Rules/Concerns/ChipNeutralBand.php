<?php

namespace App\Services\Screener\Rules\Concerns;

use RuntimeException;

/**
 * 籌碼淨額的中性帶：淨額的正負不足以判定方向，還要看它相對這檔的量算不算大。
 *
 * 與 SignalEngine::chipStance() 判的是同一件事、用同一個門檻。修正前選股器只看
 * 淨額正負，外資淨買 1 股就會讓標的出現在使用者的候選清單上，也會觸發 signal
 * 警報——那是把雜訊宣稱成訊號。
 *
 * **分母與 SignalEngine 刻意不同，那不是 bug**：SignalEngine 只拿得到技術指標
 * 快照（一組尾值，不是序列），只能用「volume_ma20 × 採計天數」估同期成交量；
 * 選股器手上有完整的價格序列，直接加總實際成交量即可。同一條門檻套在更準的
 * 分母上，結果只會更貼近「這筆量算不算大」這個問題本身。
 */
trait ChipNeutralBand
{
    /**
     * 取出成交量序列。缺鍵或型別不符時回空陣列，由呼叫端當成「算不出來」處理。
     *
     * @param  array<string, mixed>  $series
     * @return list<int|float>
     */
    protected function volumeSeries(array $series): array
    {
        $volumes = $series['volume'] ?? null;

        if (! is_array($volumes)) {
            return [];
        }

        // 非數值填 0 而不是濾掉：MarginRule 的歷史回放靠索引把序列截到該時點，
        // 濾掉會讓後面每一根的索引往前位移，截斷點就對到別的日期。
        return array_map(
            static fn ($volume): int|float => is_numeric($volume) ? $volume : 0,
            array_values($volumes),
        );
    }

    /**
     * 淨額佔同期成交量的比例。分母是序列尾端 $days 根的實際成交量合計。
     *
     * 分母無效（無成交量、合計為 0、天數小於 1）時回 null——那代表規模基準不明，
     * 不是「比例為 0」。
     *
     * @param  list<int|float>  $volumes  已截到評估時點的成交量序列
     */
    protected function volumeShare(int $net, array $volumes, int $days): ?float
    {
        // 序列涵蓋不到採計天數時同樣算不出來：拿較短的一段當分母會低估同期成交量、
        // 高估佔比，等於把雜訊推向命中那一側。
        if ($days < 1 || count($volumes) < $days) {
            return null;
        }

        $total = array_sum(array_slice($volumes, -$days));

        return $total > 0 ? $net / $total : null;
    }

    /**
     * 這筆淨額算不算顯著（達到中性帶門檻）。方向由呼叫端另外判斷。
     *
     * **規模基準不明時回 false，與 SignalEngine 的選擇相反**，因為兩者的後果不
     * 對稱：SignalEngine 是在描述一檔使用者已經點開的股票，此時退回只看正負至少
     * 保住方向資訊；選股器的命中則是把標的推到使用者面前，沒有量的基準就宣稱
     * 「法人買超」，等於用不可信的資料製造候選。而且這裡不存在 SignalEngine 那種
     * 「K 棒不足 20 根」的暖身期——選股器拿的是完整序列，算不出成交量代表該期間
     * 根本沒有成交或資料有問題，寧可漏也不要誤推。
     *
     * @param  list<int|float>  $volumes
     */
    protected function isSignificantNet(int $net, array $volumes, int $days): bool
    {
        if ($net === 0) {
            return false;
        }

        $share = $this->volumeShare($net, $volumes, $days);

        // 邊界含等於：恰好等於門檻算得上訊號，少一股才落回中性帶（與 SignalEngine 同側）。
        return $share !== null && abs($share) >= $this->chipNeutralBand();
    }

    /**
     * 中性帶門檻。
     *
     * 刻意跨命名空間讀 `health.chip`：這條帶與 SignalEngine::chipStance() 判的是
     * 同一件事，在 `screener.*` 另開一份門檻，兩邊遲早漂移——本次修的正是同一個
     * 缺陷只在其中一條路徑上被修好。真要搬家就兩處一起搬，不要各留一份。
     *
     * 缺鍵或非數值一律拋錯，不做裸 `(float) config(...)` 轉型：`(float) null` 是
     * 0.0，會讓中性帶靜默消失、「淨買 1 股＝法人買超」無聲復活。
     */
    private function chipNeutralBand(): float
    {
        $value = config('health.chip.neutral_band_volume_share');

        if (! is_numeric($value)) {
            throw new RuntimeException('health.chip.neutral_band_volume_share config 缺失或非數值，無法界定籌碼中性帶。');
        }

        return (float) $value;
    }
}
