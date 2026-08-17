<?php

namespace Tests\Feature\News;

use Tests\TestCase;

/**
 * Structural contract test for the client-rendered News/Index.jsx page.
 *
 * Inertia renders this page client-side (no SSR), and the project has no JS
 * test runner, so the controller prop contract is covered by
 * {@see NewsControllerTest}. This test guards the Task 5 UI affordances that
 * the JSX must implement so they cannot silently regress: the daily-summary
 * panel, the per-item analyze action, the sentiment chips, the chosen-provider
 * field, and the no-provider Settings fallback.
 */
class NewsIndexPageContractTest extends TestCase
{
    private function source(): string
    {
        $path = resource_path('js/Pages/News/Index.jsx');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_page_consumes_the_2b_props(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('llmProviders', $source);
        $this->assertStringContainsString('latestDailySummary', $source);
        $this->assertStringContainsString('latest_analysis', $source);
    }

    public function test_page_posts_to_the_analysis_endpoints(): void
    {
        $source = $this->source();

        // Per-item analyze action targets POST /news/{id}/analyses.
        $this->assertStringContainsString('/analyses', $source);
        $this->assertMatchesRegularExpression('#/news/\$\{[^}]+\}/analyses#', $source);

        // Daily macro summary action targets POST /news/daily-summary.
        $this->assertStringContainsString('/news/daily-summary', $source);

        // The chosen provider is passed through so the user can pick a model.
        $this->assertStringContainsString('llm_provider_setting_id', $source);
    }

    public function test_page_renders_sentiment_results(): void
    {
        $source = $this->source();

        // i18n 後 sentiment 標籤改由字典提供：頁面必須引用三個 sentiment 鍵，
        // 實際文字則存在繁中字典裡。兩者一起守住「情緒 chip 仍會渲染」的契約。
        $this->assertStringContainsString('news.sentimentBullish', $source);
        $this->assertStringContainsString('news.sentimentBearish', $source);
        $this->assertStringContainsString('news.sentimentNeutral', $source);

        $zh = (string) file_get_contents(resource_path('js/i18n/messages/zh.js'));
        $this->assertStringContainsString('偏多', $zh);
        $this->assertStringContainsString('偏空', $zh);
        $this->assertStringContainsString('中性', $zh);
    }

    public function test_page_offers_settings_fallback_when_no_provider(): void
    {
        $source = $this->source();

        // When the user has no configured provider the AI actions are hidden
        // and a link to Settings is shown instead.
        $this->assertStringContainsString('/settings', $source);
    }

    public function test_default_export_tolerates_missing_2b_props(): void
    {
        $source = $this->source();

        // Default prop values keep the page resilient when 2B props are absent.
        $this->assertStringContainsString('llmProviders = []', $source);
        $this->assertStringContainsString('latestDailySummary = null', $source);
    }
}
