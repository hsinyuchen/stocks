<?php

namespace App\Http\Middleware;

use App\Support\FinMindTokenResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 讓當前登入使用者的 web 請求，在即時抓取台股資料時用「自己的」FinMind token。
 *
 * handle 一律呼叫 useUserToken：使用者沒設 token 或未登入時，resolver 退回全站 env
 * token。terminate 明確 reset，避免 override 在常駐 runtime（octane）跨請求殘留——見
 * FinMindTokenResolver 的正確性說明。注入的 resolver 為 singleton，handle 與 terminate
 * 操作的是同一實例。
 */
class ApplyFinMindToken
{
    public function __construct(private readonly FinMindTokenResolver $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tokens->useUserToken($request->user());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tokens->reset();
    }
}
