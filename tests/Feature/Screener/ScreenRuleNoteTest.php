<?php

namespace Tests\Feature\Screener;

use App\Models\Alert;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Screener\Rules\EarlySocialArbitrage;
use App\Services\Screener\Rules\IndustryOutperformer;
use App\Services\Screener\ScreenRuleNote;
use App\Services\Screener\ScreenRuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 規則附註（階段 4 最終審查的 I4）。
 *
 * 個股分析 prompt 與個股頁面板都強制輸出「只涵蓋新聞熱度」與「門檻未經回測」
 * 這兩則聲明，但選股器與警報兩個消費端原本只拿得到 label，沒有任何更正機會。
 */
class ScreenRuleNoteTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function dictionaryKeys(string $locale): array
    {
        $source = (string) file_get_contents(resource_path("js/i18n/messages/{$locale}.js"));
        $start = strpos($source, '{');
        $end = strrpos($source, '}');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $decoded = json_decode(substr($source, (int) $start, (int) $end - (int) $start + 1), true);

        $this->assertIsArray($decoded, "{$locale}.js 無法以 JSON 解析");

        $flat = [];
        $this->flatten($decoded, '', $flat);

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $out
     */
    private function flatten(array $node, string $prefix, array &$out): void
    {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $this->flatten($value, $path, $out);

                continue;
            }

            $out[] = $path;
        }
    }

    #[Test]
    public function the_two_rules_that_can_mislead_carry_notes(): void
    {
        $this->assertNotEmpty((new EarlySocialArbitrage)->noteKeys());
        $this->assertNotEmpty((new IndustryOutperformer)->noteKeys());
    }

    /**
     * 附註是 i18n 鍵，鍵不存在時使用者看到的是 `screener.noteSocialCoverage`
     * 這種原始字串——比不顯示更糟。parity 測試只驗中英對稱，不驗鍵存不存在，
     * 兩本字典同時漏掉同一個鍵它照樣綠。
     */
    #[Test]
    public function every_note_key_resolves_in_both_dictionaries(): void
    {
        $zh = $this->dictionaryKeys('zh');
        $en = $this->dictionaryKeys('en');

        $this->assertContains('screener.ruleNotesLabel', $zh);
        $this->assertContains('screener.ruleNotesLabel', $en);

        $notes = [];

        foreach (app(ScreenRuleRegistry::class)->all() as $rule) {
            if ($rule instanceof ScreenRuleNote) {
                $notes = [...$notes, ...$rule->noteKeys()];
            }
        }

        $this->assertNotEmpty($notes, '註冊表裡必須至少有一條帶附註的規則，否則本測試等於沒測');

        foreach ($notes as $key) {
            $this->assertContains($key, $zh, "繁中字典缺少附註鍵 {$key}，使用者會看到原始鍵");
            $this->assertContains($key, $en, "英文字典缺少附註鍵 {$key}，使用者會看到原始鍵");
        }
    }

    /**
     * 兩個消費端**共用同一份 listing**。這條測試釘的不是「有沒有 notes」，
     * 而是「兩邊拿到的是不是同一份」——payload 形狀曾經各自寫在兩個 controller
     * 裡，加欄位時只改到一邊，漏掉的那一邊就會繼續只顯示 label。
     */
    #[Test]
    public function the_screener_and_the_alerts_page_carry_the_same_rule_listing(): void
    {
        $expected = app(ScreenRuleRegistry::class)->listing();

        $annotated = array_values(array_filter($expected, fn (array $row): bool => $row['notes'] !== []));
        $this->assertCount(2, $annotated, '目前只有社交套利與產業動能兩條規則帶附註');

        $user = User::factory()->create();

        $this->actingAs($user)->get('/screener')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('rules', $expected)->etc(),
        );

        $this->actingAs($user)->get('/alerts')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('signalRules', $expected)->etc(),
        );
    }

    /**
     * 首頁的已觸發訊號警報：規則名稱與附註由後端解好。
     *
     * 這裡原本直接把 `signal_key` 印給使用者（「訊號 early_social_arbitrage」）
     * ——機器鍵對使用者毫無意義，而且那是 25 條規則共同的問題，不只帶附註的兩條。
     *
     * 走 partial 請求而不是 assertInertia：首頁的重資料是 Inertia deferred props，
     * 整頁請求拿不到 triggeredAlerts。
     */
    #[Test]
    public function the_dashboard_resolves_the_signal_label_and_notes(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $alert = new Alert([
            'instrument_id' => $instrument->id,
            'type' => 'signal',
            'signal_key' => 'early_social_arbitrage',
        ]);
        $user->alerts()->save($alert);
        // status／triggered_at 不在 fillable 裡（避免從請求塞觸發狀態），測試改直接寫。
        $alert->forceFill(['status' => 'triggered', 'triggered_at' => now()])->save();

        $payload = $this->actingAs($user)->getDashboard()->assertOk()->json('props.triggeredAlerts');

        $this->assertCount(1, $payload);
        $this->assertSame('早期社交套利', $payload[0]['signal_label'], '不得把機器鍵印給使用者');
        $this->assertSame(
            (new EarlySocialArbitrage)->noteKeys(),
            $payload[0]['signal_notes'],
            '觸發後回頭看首頁、據以動作的那一刻，跟建立警報的當下一樣需要那兩則更正',
        );
    }

    /**
     * 附註在兩個頁面都必須是**無條件渲染**。
     *
     * 藏進摺疊或 tooltip 等於沒說——階段 4 的個股頁面板已立過同一條標準。
     */
    #[Test]
    public function the_notes_are_never_collapsed_on_either_page(): void
    {
        foreach (['Screener/Index.jsx', 'Alerts/Index.jsx'] as $page) {
            $source = (string) file_get_contents(resource_path("js/Pages/{$page}"));
            $body = $this->componentBody($source, 'RuleNotes');

            foreach (['<details', '<summary', 'Collapsible', 'collapsed', 'aria-expanded', 'useState', 'title='] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $body,
                    "{$page} 的 RuleNotes 不得把必要說明藏起來（{$forbidden}）",
                );
            }
        }
    }

    /**
     * 取出某個 top-level function 元件的主體。
     *
     * 用行首的 `}` 收尾，**不要用大括號計數**：`function RuleNotes({ rules })` 的
     * 第一個 `{` 是參數解構，計數會在簽名處就歸零，回傳的東西不含任何 JSX——
     * 那樣的斷言看起來在檢查渲染，實際上只掃過函式簽名，永遠不會紅。
     * 這個寫法與 SocialArbitragePanelTest::functionBody() 一致。
     */
    private function componentBody(string $source, string $component): string
    {
        $start = strpos($source, "function {$component}(");

        $this->assertNotFalse($start, "找不到元件 {$component}");

        $end = strpos($source, '
}
', (int) $start);

        $this->assertNotFalse($end, "元件 {$component} 的結尾找不到");

        return substr($source, (int) $start, (int) $end - (int) $start);
    }
}
