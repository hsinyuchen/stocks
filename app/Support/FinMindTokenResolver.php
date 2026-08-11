<?php

namespace App\Support;

use App\Models\User;

/**
 * 決定「這次 FinMind 呼叫要用誰的 token」。
 *
 * 語意：有設定使用者 token → 用使用者的（用他自己的免費額度）；否則退回全站 env
 * token（FINMIND_TOKEN）。抓回的資料仍進全站共用快取，token 只影響「用誰的額度抓」。
 *
 * 註冊為容器 singleton，override 為 request/job-scoped 狀態。
 *
 * 正確性關鍵（queue worker 常駐）：queue:work 是常駐 process，singleton 跨 job 存活，
 * override 會殘留。因此每個進入點（middleware、每個會抓 FinMind 的 job）都必須在開頭
 * 明確 useUserToken()、結束 reset()，不可依賴殘留值——否則 job A 的 token 會被沒有明確
 * 設定的 job B 誤用（跨使用者盜用額度）。Web 端 FPM 雖每 request 獨立，仍一律明確
 * 設定/重置，對 octane 等常駐 runtime 也安全。
 */
class FinMindTokenResolver
{
    /** null＝不覆蓋，resolve() 退回全站 token。 */
    private ?string $override = null;

    /** 當前應使用的 token；null 代表全站與使用者都沒有 token（provider 打無 token 請求）。 */
    public function resolve(): ?string
    {
        return $this->override ?? $this->globalToken();
    }

    /**
     * 套用某使用者的 token。使用者沒設定或 token 為空 → 不覆蓋（等同退回全站）。
     */
    public function useUserToken(?User $user): void
    {
        $token = $user?->finmindSetting?->token_encrypted;

        $this->override = filled($token) ? (string) $token : null;
    }

    /** 直接指定覆蓋 token（測試與特殊場景用）。空字串視為清除。 */
    public function useToken(?string $token): void
    {
        $this->override = filled($token) ? $token : null;
    }

    /** 清除覆蓋，退回全站 token。 */
    public function reset(): void
    {
        $this->override = null;
    }

    private function globalToken(): ?string
    {
        $token = config('services.finmind.token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
