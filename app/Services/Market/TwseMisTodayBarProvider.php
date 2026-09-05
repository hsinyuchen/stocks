<?php

namespace App\Services\Market;

use App\Contracts\TodayBarProvider;
use App\Data\DailyPriceData;
use App\Support\MarketResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 台股當日 K 棒：證交所 MIS 即時報價（`mis.twse.com.tw`）。
 *
 * **為什麼需要這個來源。** FinMind 的 `TaiwanStockPrice` 是日線資料集，當日收盤
 * 要數小時後才補，而且**分市場**：2026-09-02 實測 14:41（13:30 已收盤），上市的
 * 2330／2317／0050 已有當日資料，上櫃的 8299／6488 還沒有；同時間櫃買中心自己的
 * openapi 也還停在 09-01。於是 K 線圖與所有技術指標在收盤當天都少最新一根。
 *
 * MIS 沒有這個落差：**上市（`tse_`）與上櫃（`otc_`）走同一個端點、同一份回應**，
 * 收盤後即帶完整的當日 OHLC、昨收與累計成交量。
 *
 * **量是官方值，這是選它而不選 Yahoo 的關鍵。** Yahoo 的台股 OHLC 與 FinMind
 * 一年份實測零差異，但成交量差 0.54~1.18 倍（09-01 的 2330：Yahoo 17.2M vs
 * 官方 31.9M），會污染 OBV 與 `volume_ma20`——後者是 SignalEngine 判斷「這筆
 * 買賣超算不算大」的分母。MIS 的 `v`（張）×1000 與 FinMind 的 `Trading_Volume`
 * （股）實測一字不差（2330 19,752 張、2317 27,660 張）。
 *
 * **已知風險**：MIS 是證交所看盤系統的後端，不是文件化的開放 API，限流門檻未知、
 * 也可能改版。所以這裡一律 best-effort（失敗回空陣列），呼叫端沿用既有序列，
 * 降級後只是回到「少最新一根」，不會壞掉。
 */
class TwseMisTodayBarProvider implements TodayBarProvider
{
    private const ENDPOINT = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp';

    /** 單次請求的標的數上限。實測 30 檔一次回滿；上游未公布上限，保守分批。 */
    private const CHUNK = 30;

    /**
     * 逾時取短：這是補強不是主源，而消費端目前逐檔呼叫——自選快報掃 30 檔、MIS 掛掉
     * 時每檔都等滿逾時，10 秒會撞到 ANALYSIS_INLINE_WORKER 下的 max_execution_time。
     */
    public function __construct(private readonly int $timeoutSeconds = 5) {}

    public function todayBars(array $symbols): array
    {
        // 只服務台股。isTaiwan() 要求 symbol 帶 .TW/.TWO，正好也是決定
        // tse_/otc_ 前綴的依據——前綴猜錯 MIS 就查無，不能靠代號推斷。
        $taiwan = array_values(array_unique(array_filter(
            array_map(static fn (string $symbol): string => strtoupper(trim($symbol)), $symbols),
            static fn (string $symbol): bool => MarketResolver::isTaiwan($symbol),
        )));

        if ($taiwan === []) {
            return [];
        }

        $bars = [];

        foreach (array_chunk($taiwan, self::CHUNK) as $chunk) {
            foreach ($this->fetchChunk($chunk) as $symbol => $bar) {
                $bars[$symbol] = $bar;
            }
        }

        return $bars;
    }

    /**
     * @param  list<string>  $symbols
     * @return array<string, DailyPriceData>
     */
    private function fetchChunk(array $symbols): array
    {
        $channels = [];

        foreach ($symbols as $symbol) {
            $channels[$this->channel($symbol)] = $symbol;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; StockRadar/1.0)'])
                ->acceptJson()
                ->get(self::ENDPOINT, [
                    'ex_ch' => implode('|', array_keys($channels)),
                    'json' => 1,
                    'delay' => 0,
                ]);

            if ($response->failed()) {
                return [];
            }

            $rows = $response->json('msgArray');
        } catch (Throwable) {
            // best-effort：逾時、DNS、TLS 都當成「這次補不到」。
            return [];
        }

        if (! is_array($rows)) {
            return [];
        }

        $bars = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $symbol = $this->symbolFor($row, $channels);

            if ($symbol === null) {
                continue;
            }

            $bar = $this->toBar($symbol, $row);

            if ($bar !== null) {
                $bars[$symbol] = $bar;
            }
        }

        return $bars;
    }

    /**
     * 回應列對應回請求時用的 symbol。
     *
     * MIS 的每一列帶 `c`（代號）與 `ex`（tse/otc），組回 channel 才能對上原始
     * symbol——只比對 `c` 會在同代號跨市場時配錯。查無資料的佔位列 `c` 為空字串，
     * 於是自然落到「對不上」而被跳過。
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $channels
     */
    private function symbolFor(array $row, array $channels): ?string
    {
        $code = strtoupper($this->text($row['c'] ?? null));
        $exchange = strtolower($this->text($row['ex'] ?? null));

        if ($code === '' || $exchange === '') {
            return null;
        }

        return $channels[$exchange.'_'.$code.'.tw'] ?? null;
    }

    /** @param array<string, mixed> $row */
    private function toBar(string $symbol, array $row): ?DailyPriceData
    {
        $date = $this->date($row['d'] ?? null);

        if ($date === null) {
            return null;
        }

        $open = $this->number($row['o'] ?? null);
        $high = $this->number($row['h'] ?? null);
        $low = $this->number($row['l'] ?? null);
        // 逐筆交易後，盤中快照沒撮合到的那一刻 z 會是 '-'，最近成交價在 pz。
        // 這是社群對 getStockInfo.jsp 的共同紀錄，本專案尚未在盤中親眼驗過；
        // 沒有它的話冷門股的當日棒會忽隱忽現，還被 negative cache 記 60 秒。
        $close = $this->number($row['z'] ?? null) ?? $this->number($row['pz'] ?? null);

        // 開盤前與整日無成交時這些欄位是 '-'。缺任何一個就不產棒：半根 K 棒
        // 進到指標計算裡，比少一根更難查。
        if ($open === null || $high === null || $low === null || $close === null) {
            return null;
        }

        return new DailyPriceData(
            symbol: $symbol,
            date: $date,
            open: $open,
            high: $high,
            low: $low,
            close: $close,
            // MIS 的 v 是「張」，DailyPrice 全站以「股」為單位（FinMind 的
            // Trading_Volume 亦然）。實測 2330 的 19,752 張 ×1000 與 FinMind 相等。
            volume: (int) round(($this->number($row['v'] ?? null) ?? 0.0) * 1000),
            partial: $this->isPartial($date),
        );
    }

    /**
     * 這根是不是還在盤中。
     *
     * 以台北時間判斷「是今天且還沒到 13:30」，不用回應裡的 `t`（最後成交時間）：
     * 冷門股最後一筆可能落在 13:2x，收盤後看起來仍像盤中。收盤後 MIS 的值就是
     * 定案值（與 FinMind 實測一致）。
     */
    private function isPartial(string $date): bool
    {
        $taipei = CarbonImmutable::now('Asia/Taipei');

        return $date === $taipei->toDateString() && $taipei->format('H:i:s') < '13:30:00';
    }

    /** 外部 JSON 的欄位不保證是字串：陣列強轉會拋 ErrorException，繞過 best-effort 邊界。 */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * `20260902` → `2026-09-02`。非八位數字、或不是真實日期（`20269999`）一律不可用：
     * 週／月聚合會拿這個字串去 parse，錯的日期在那裡才炸、更難追。
     */
    private function date(mixed $value): ?string
    {
        $raw = $this->text($value);

        if (preg_match('/^\d{8}$/', $raw) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) substr($raw, 0, 4), (int) substr($raw, 4, 2), (int) substr($raw, 6, 2)];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * MIS 用 '-' 表示「沒有這個值」，空字串與非數字同樣視為缺值。
     * `1e309` 這種轉出來是 INF，JSON 序列化會失敗，一併擋掉。
     */
    private function number(mixed $value): ?float
    {
        $raw = $this->text($value);

        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $number = (float) $raw;

        return is_finite($number) ? $number : null;
    }

    /** `2330.TW` → `tse_2330.tw`；`8299.TWO` → `otc_8299.tw`。 */
    private function channel(string $symbol): string
    {
        $prefix = str_ends_with($symbol, '.TWO') ? 'otc' : 'tse';

        return $prefix.'_'.MarketResolver::taiwanCode($symbol).'.tw';
    }
}
