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

        $this->assertStringContainsString('偏多', $source);
        $this->assertStringContainsString('偏空', $source);
        $this->assertStringContainsString('中性', $source);
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
