<?php

namespace App\Services\Screener\Rules\Concerns;

use App\Data\ChipFlowData;
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
 * 選股器手上有完整的價格序列，可以依籌碼的實際日期逐日取量加總。同一條門檻套在
 * 更準的分母上，結果只會更貼近「這筆量算不算大」這個問題本身。
 *
 * **分子與分母必須是同一段日子**：籌碼與價格是兩個上游、兩份交易日曆，籌碼落後
 * 於價格是常態（正式資料實測 21 檔有 8 檔尾端日期不一致，最久差 6 個交易日，
 * 5 日窗口完全零重疊）。以筆數對齊（取價格序列尾端 N 根）會讓分母變成另一段
 * 期間的成交量，實測最大誤差 2.3 倍。因此這裡一律以**日期**對齊。
 */
trait ChipNeutralBand
{
    /**
     * 成交量的「日期 → 量」映射，供中性帶依籌碼日期取分母。
     *
     * `dates` 與 `volume` 由 TechnicalIndicatorService::series() 逐索引配對產生，
     * 這裡沿用同一個配對關係。缺鍵、型別不符或兩者長度不一致時回空陣列，由呼叫端
     * 當成「算不出來」處理——長度對不上代表兩個序列不是同一組 K 棒的產物，硬配對
     * 只會把量對到別的日期上。
     *
     * @param  array<string, mixed>  $series
     * @param  int|null  $bars  只採計前 $bars 根 K 棒（歷史回放用），null 為全序列
     * @return array<string, int|float>
     */
    protected function volumeByDate(array $series, ?int $bars = null): array
    {
        $dates = $series['dates'] ?? null;
        $volumes = $series['volume'] ?? null;

        if (! is_array($dates) || ! is_array($volumes)) {
            return [];
        }

        $dates = array_values($dates);
        $volumes = array_values($volumes);

        if (count($dates) !== count($volumes)) {
            return [];
        }

        if ($bars !== null) {
            if ($bars < 1) {
                return [];
            }

            $dates = array_slice($dates, 0, $bars);
            $volumes = array_slice($volumes, 0, $bars);
        }

        $map = [];

        foreach ($dates as $index => $date) {
            $key = self::normalizeDate($date);

            if ($key === null) {
                continue;
            }

            // 非數值填 0 而不是跳過：那一天確實有 K 棒，只是量不可信。跳過會讓它
            // 變成「缺日」，把整段判定作廢；填 0 只讓分母少那一天的量，與既有行為一致。
            $map[$key] = is_numeric($volumes[$index]) ? $volumes[$index] : 0;
        }

        return $map;
    }

    /**
     * 淨額佔同期成交量的比例。分母是**這幾筆籌碼各自那一天**的成交量合計。
     *
     * 分母無效時回 null——那代表規模基準不明，不是「比例為 0」：
     * - 沒有成交量映射、沒有籌碼、或合計為 0；
     * - 採計的籌碼日裡有任何一天在映射裡找不到。
     *
     * 缺日一律作廢而不是「只算找得到的那幾天」：後者會讓分母變小、佔比變大，把
     * 雜訊推向命中那一側，正是這條中性帶要擋的方向。正式資料實測缺日比例
     * 0.24%（滑動 5 日窗口 1.23%），代價可接受。
     *
     * @param  array<string, int|float>  $volumeByDate  已截到評估時點的日期 → 量
     * @param  list<ChipFlowData>  $flows  實際採計的那幾筆籌碼
     */
    protected function volumeShare(int $net, array $volumeByDate, array $flows): ?float
    {
        if ($volumeByDate === [] || $flows === []) {
            return null;
        }

        $total = 0;

        foreach ($flows as $flow) {
            $date = self::normalizeDate($flow->date);

            if ($date === null || ! array_key_exists($date, $volumeByDate)) {
                return null;
            }

            $total += $volumeByDate[$date];
        }

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
     * @param  array<string, int|float>  $volumeByDate
     * @param  list<ChipFlowData>  $flows
     */
    protected function isSignificantNet(int $net, array $volumeByDate, array $flows): bool
    {
        if ($net === 0) {
            return false;
        }

        $share = $this->volumeShare($net, $volumeByDate, $flows);

        // 邊界含等於：恰好等於門檻算得上訊號，少一股才落回中性帶（與 SignalEngine 同側）。
        return $share !== null && abs($share) >= $this->chipNeutralBand();
    }

    /**
     * 日期正規化成 `YYYY-MM-DD`，無法辨識時回 null。
     *
     * 兩邊的日期都是字串但來源不同：籌碼一律走 ChipFlow 的 `date:Y-m-d` cast，
     * 價格則多數走 CarbonImmutable::toDateString()，只有 FinMind 那條路徑是把上游的
     * `date` 欄位原樣轉字串。上游改成帶時刻的形式不能排除，而對不上會讓整檔靜默
     * 不命中——比誤命中更難察覺，所以這裡先削掉時刻再比對，不直接 `===`。
     *
     * 不做日曆有效性檢查：這裡要的是穩定的對應鍵，不是日期驗證。
     */
    private static function normalizeDate(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        // 同時吃 "2026-07-01 00:00:00" 與 ISO8601 的 "2026-07-01T00:00:00Z"。
        $value = (string) preg_split('/[ T]/', $value)[0];

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
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
