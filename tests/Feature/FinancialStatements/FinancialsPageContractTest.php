<?php

namespace Tests\Feature\FinancialStatements;

use Tests\Feature\News\NewsIndexPageContractTest;
use Tests\TestCase;

/**
 * 財報子頁面的結構契約（JSX 由前端渲染，專案沒有 JS test runner）。
 *
 * 寫法與 {@see NewsIndexPageContractTest} 同一種：只釘住
 * 「這個接線還在」，不複製使用者看得到的文案（那是字典的事，見
 * {@see FinancialsLabelContractTest}）。
 */
class FinancialsPageContractTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = resource_path($relative);

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * 財報頁不攔截離開。
     *
     * useAnalysisPolling 綁了輪詢與 beforeunload 警告兩件事，後者的理由是
     * 「使用者主動按了分析、結果只存在這一次工作階段」。財報的抓取是造訪頁面時
     * 自動派工、結果永久落進 financial_statements 表，而且首訪任何標的的 state
     * 必為 fetching／refreshing——不 opt-out 的話等於每次首訪都武裝一次離開警告。
     */
    public function test_the_financials_page_opts_out_of_the_leave_warning(): void
    {
        $this->assertMatchesRegularExpression(
            '/useAnalysisPolling\(\s*inFlight\s*,\s*\[[^\]]*\]\s*,\s*\{\s*warnOnLeave:\s*false\s*\}\s*\)/',
            $this->source('js/Pages/Stocks/Financials.jsx'),
            '財報頁必須以 warnOnLeave: false 呼叫 useAnalysisPolling。',
        );
    }

    /**
     * 而 opt-out 是 opt-out：預設仍要警告。
     *
     * 另外四個呼叫端（Market/WeightAnalysis、News/Index、Stocks/Search、
     * Watchlists/Analysis）都是使用者主動觸發的 LLM 分析，只傳兩個引數，靠的就是
     * 這個預設值。把預設翻成 false 會一次拆掉那四頁的離開提醒而沒有任何訊號。
     */
    public function test_the_leave_warning_still_defaults_to_on(): void
    {
        $this->assertMatchesRegularExpression(
            '/warnOnLeave\s*=\s*true/',
            $this->source('js/hooks/useAnalysisPolling.js'),
            'useAnalysisPolling 的 warnOnLeave 預設必須是 true。',
        );

        foreach ([
            'js/Pages/Market/WeightAnalysis.jsx',
            'js/Pages/News/Index.jsx',
            'js/Pages/Stocks/Search.jsx',
            'js/Pages/Watchlists/Analysis.jsx',
        ] as $page) {
            $this->assertDoesNotMatchRegularExpression(
                '/useAnalysisPolling\([^)]*warnOnLeave/',
                $this->source($page),
                "{$page} 是使用者主動觸發的分析，不該關掉離開提醒。",
            );
        }
    }

    /**
     * unsupported ＋ 有舊列時橫幅要換一句話。
     *
     * 這個組合到得了前端（見 {@see FinancialsPageTest::test_unsupported_with_existing_rows_still_ships_the_rows()}），
     * 而表格只看 periods.length、不看 state，照樣把整份財報畫出來。橫幅若還說
     * 「此標的沒有可取得的財報」，畫面上就是兩句互相打臉的話。
     */
    public function test_the_banner_distinguishes_unsupported_with_history(): void
    {
        $source = $this->source('js/Pages/Stocks/Financials.jsx');

        $this->assertStringContainsString('hasPeriods', $source, 'StatusBanner 需要知道下面畫不畫得出表格。');
        $this->assertStringContainsString(
            'financials.state.unsupportedWithHistory',
            $source,
            'unsupported ＋ 有舊列時要改用「已不再更新財報」那句文案。',
        );
    }
}
