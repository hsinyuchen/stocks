<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * 取儀表板並解析 deferred props。
     *
     * Dashboard 的重資料（行情、新聞、大盤風向、警報）以 Inertia deferred props 提供，
     * 不在初始 response——前端外殼先渲染、顯示 loading，再以 partial 請求載入。測試要
     * 驗這些 props 必須模擬該 partial 請求（列出所有欄位，含非 deferred 的 auth/disclaimer/
     * hasLlmProvider，一次取齊）。呼叫前需先 actingAs。
     */
    protected function getDashboard(bool $refresh = false): TestResponse
    {
        return $this->get('/dashboard'.($refresh ? '?refresh=1' : ''), [
            'X-Inertia' => 'true',
            // 與 Inertia\Middleware::version 同法算資產版本，否則 partial 請求會被判為
            // 版本不符回 409（要求整頁重載）。
            'X-Inertia-Version' => file_exists($manifest = public_path('build/manifest.json'))
                ? hash_file('xxh128', $manifest)
                : '',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => implode(',', [
                'auth', 'disclaimer', 'hasLlmProvider',
                'marketSnapshot', 'marketBreadth', 'watchlistMovers', 'watchlistCoverage',
                'latestNews', 'transmissionFocus', 'recentAnalyses', 'generatedAt', 'triggeredAlerts',
            ]),
        ]);
    }
}
