<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * FinMind 免費層額度守門（全站共用熔斷）。
 *
 * 免費層有每小時請求上限。自選快報一次會對多檔股票打行情、籌碼、融資、基本面，
 * 外加期貨三個端點——單次分析就可能數十到上百次 FinMind 呼叫。撞到上限時若每個
 * provider 各自繼續重試，只會把已經耗盡的額度打得更兇（甚至被上游短暫封鎖）。
 *
 * 這個守門在偵測到「額度/等級受限」的回應時開啟冷卻：冷卻期內所有 FinMind
 * provider 直接跳過呼叫、走 best-effort 降級（標「無法取得」），等額度恢復。
 *
 * 與各 provider 既有的 per-symbol failure throttle 互補：那個防的是「單一標的
 * 反覆失敗」，這個防的是「整批對已耗盡的額度雪崩式重打」。
 *
 * 無狀態，狀態存 Cache（全站共用）。
 */
class FinMindGate
{
    private const KEY = 'finmind:cooldown';

    /** 冷卻中：呼叫端應跳過 FinMind、走降級。 */
    public static function isTripped(): bool
    {
        return self::enabled() && Cache::has(self::KEY);
    }

    /** 開啟冷卻。TTL 由 config 決定，預設 10 分鐘（免費層上限多為每小時，10 分足以讓額度回補一部分）。 */
    public static function trip(): void
    {
        Cache::put(self::KEY, true, now()->addMinutes(max(1, (int) config('finmind.cooldown_minutes', 10))));
    }

    /**
     * 回應是否代表 FinMind 額度/等級受限；命中即開啟冷卻並回 true。
     *
     * 只認明確可判定的訊號，避免把一般 5xx/逾時誤判成額度耗盡（那些仍由各
     * provider 自己的 failure throttle 處理）：
     * - HTTP 402 Payment Required / 429 Too Many Requests。
     * - 回應 msg 含 limit / level is free / upgrade / exceed（免費超限與付費牆用語）。
     */
    public static function limited(Response $response): bool
    {
        if (! self::enabled()) {
            return false;
        }

        if (in_array($response->status(), [402, 429], true)) {
            self::trip();

            return true;
        }

        $msg = strtolower((string) $response->json('msg'));

        if ($msg !== '' && (
            str_contains($msg, 'limit')
            || str_contains($msg, 'level is free')
            || str_contains($msg, 'upgrade')
            || str_contains($msg, 'exceed')
        )) {
            self::trip();

            return true;
        }

        return false;
    }

    private static function enabled(): bool
    {
        return (bool) config('finmind.gate_enabled', true);
    }
}
