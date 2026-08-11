<?php

use App\Http\Middleware\ApplyFinMindToken;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProcessQueuedAnalyses;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnsureUserIsActive::class,
            // 登入使用者的即時抓取用自己的 FinMind token（未設定則退回全站 env）。
            ApplyFinMindToken::class,
            // 沒有常駐 worker 的環境靠這個推動佇列；工作排在回應送出之後。
            ProcessQueuedAnalyses::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
