<?php

namespace Tests\Feature\Admin;

use Tests\Feature\News\NewsIndexPageContractTest;
use Tests\TestCase;

/**
 * TopicForm.jsx 的結構契約測試。
 *
 * 頁面由 Inertia 客戶端渲染（無 SSR），專案也沒有 JS 測試跑者，因此「試跑」
 * 這段的行為只能靠讀原始碼字串來釘住，手法沿用
 * {@see NewsIndexPageContractTest}。
 *
 * 這裡守的兩個缺陷都只有 UI 層會出錯，後端的 session/日誌斷言測不到：
 * 1. runPreview() 用全域 router.post 而非 useForm() 的 post/patch，
 *    驗證失敗不會自動落進 form.errors，必須自己接 onError 才會顯示。
 * 2. 試跑刻意忽略 is_active（見 TopicRulePreviewTest），但前端要把
 *    後端回傳的 rule_disabled 旗標畫成一則提示，否則管理員會誤以為停用中
 *    的規則存檔後照樣生效。
 */
class TopicFormPageContractTest extends TestCase
{
    private function source(): string
    {
        $path = resource_path('js/Pages/Admin/TopicForm.jsx');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_run_preview_handles_the_validation_error_callback(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            '/const runPreview = \(\) => \{.*?onError.*?\};/s',
            $source,
            'runPreview() 必須帶 onError 回呼，否則 router.post 的驗證錯誤不會進到任何畫面能讀到的 state。'
        );
    }

    public function test_preview_errors_are_rendered_from_local_state(): void
    {
        $source = $this->source();

        // 錯誤必須存進獨立的 local state，而不是誤用 useForm() 的 form.errors
        // （那個只有 useForm 實例自己的 post/patch 呼叫的 onError 才會寫入）。
        $this->assertStringContainsString('useState', $source);
        $this->assertStringContainsString('previewErrors', $source);
        $this->assertStringContainsString("t('adminTopics.previewFailed')", $source);
    }

    public function test_preview_result_surfaces_the_rule_disabled_flag(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('preview.rule_disabled', $source);
        $this->assertStringContainsString("t('adminTopics.previewRuleDisabled')", $source);
    }

    public function test_new_i18n_keys_are_defined_in_both_dictionaries(): void
    {
        $zh = (string) file_get_contents(resource_path('js/i18n/messages/zh.js'));
        $en = (string) file_get_contents(resource_path('js/i18n/messages/en.js'));

        foreach (['previewFailed', 'previewRuleDisabled', 'reload'] as $key) {
            $this->assertStringContainsString("\"{$key}\":", $zh, "zh.js 缺少 adminTopics.{$key}");
            $this->assertStringContainsString("\"{$key}\":", $en, "en.js 缺少 adminTopics.{$key}");
        }
    }

    /**
     * 樂觀鎖衝突後必須有「重新載入」入口。
     *
     * useForm() 對非 GET 預設 preserveState: true，衝突後 back() 不會重掛元件，
     * form.data.updated_at 停在舊值，再存幾次都撞同一個鎖，而文案叫使用者
     * 重新載入卻沒有入口——見 TopicRuleUpdateTest::test_stale_updated_at_is_rejected。
     */
    public function test_stale_lock_error_offers_a_reload_action(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            '/form\.errors\.updated_at.*?router\.reload\(\{\s*preserveState:\s*false\s*\}\).*?adminTopics\.reload/s',
            $source,
            '樂觀鎖衝突的錯誤區塊必須提供 router.reload({ preserveState: false }) 的重新載入按鈕。'
        );
    }
}
