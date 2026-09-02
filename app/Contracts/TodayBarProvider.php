<?php

namespace App\Contracts;

use App\Data\DailyPriceData;

/**
 * 當日 K 棒來源：補上「日線資料源還沒補、但當天其實已經產生」的那一根。
 *
 * 刻意不併進 {@see MarketDataProvider}：這裡只給得出最近一個交易日的一根，
 * 給不出歷史序列，硬塞進 `dailyPrices($symbol, $days)` 的簽名會是謊。
 * 為什麼需要它，見 `TwseMisTodayBarProvider`。
 */
interface TodayBarProvider
{
    /**
     * 最近一個交易日的 K 棒，key 為傳入的 symbol（大寫）。
     *
     * 盤中回的是**未完成棒**：high／low／close／volume 都還會再變，收盤後才定案。
     *
     * 取不到的 symbol 不出現在結果裡——呼叫端據此沿用既有序列，而不是收到一根
     * 空棒。整批失敗回空陣列而非拋例外：這是補強，上游掛掉不該讓行情查詢一起死。
     *
     * @param  list<string>  $symbols
     * @return array<string, DailyPriceData>
     */
    public function todayBars(array $symbols): array;
}
