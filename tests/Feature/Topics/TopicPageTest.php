<?php

namespace Tests\Feature\Topics;

use App\Enums\RevenueUnknownReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 題材候選頁的前端契約。
 *
 * 專案沒有 JS test runner，所以這些是**原始碼契約測試**：把「必要說明不可摺疊」
 * 「四態徽章不可合併」「前端不重算判定」這幾條寫成對 JSX 原始碼的斷言。
 * 這類斷言的風險是「看起來在檢查渲染、實際只掃過函式簽名」——所以元件主體一律
 * 用行首的 `}` 取，不用大括號計數（`function X({ a })` 的第一個 `{` 是參數解構，
 * 計數會在簽名處就歸零）。寫法與 ScreenRuleNoteTest::componentBody() 一致。
 */
class TopicPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 五則必要說明。順序與規格一致，內容不得少於這五則。
     */
    private const REQUIRED_NOTE_KEYS = [
        'topics.noteChainIsCurated',
        'topics.noteDirectionIsAnnotation',
        'topics.notePeripheryIsCoMention',
        'topics.noteExtensionTaiwanOnly',
        'topics.noteRevenueUnknown',
    ];

    private function jsx(): string
    {
        $path = resource_path('js/Pages/Topics/Index.jsx');

        $this->assertFileExists($path, '題材頁元件必須落在 Pages/Topics/Index.jsx，否則 Inertia 解不到。');

        return (string) file_get_contents($path);
    }

    /**
     * 取出某個 top-level function 元件的主體（到行首的 `}` 為止）。
     */
    private function componentBody(string $source, string $component): string
    {
        $start = strpos($source, "function {$component}(");

        $this->assertNotFalse($start, "找不到元件 {$component}()。");

        $end = strpos($source, "\n}\n", (int) $start);

        $this->assertNotFalse($end, "元件 {$component}() 的結尾找不到。");

        return substr($source, (int) $start, (int) $end - (int) $start);
    }

    /**
     * 字典的攤平鍵集合。與 I18nMessageParityTest 同一種解析方式。
     *
     * @return list<string>
     */
    private function dictionaryKeys(string $locale): array
    {
        $flat = [];
        $this->flatten($this->dictionary($locale), '', $flat);

        return $flat;
    }

    /** @return array<string, mixed> */
    private function dictionary(string $locale): array
    {
        $source = (string) file_get_contents(resource_path("js/i18n/messages/{$locale}.js"));
        $start = strpos($source, '{');
        $end = strrpos($source, '}');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $decoded = json_decode(substr($source, (int) $start, (int) $end - (int) $start + 1), true);

        $this->assertIsArray($decoded, "{$locale}.js 無法以 JSON 解析");

        return $decoded;
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

    // ------------------------------------------------------------------
    // i18n 鍵
    // ------------------------------------------------------------------

    /**
     * 鍵不存在時使用者看到的是 `topics.noteChainIsCurated` 這種原始字串，比不顯示
     * 更糟。既有的 I18nMessageParityTest 只驗中英**對稱**——兩本字典同時漏掉同一
     * 個鍵它照樣綠，所以存在性要另外斷言。
     */
    #[Test]
    public function every_topics_key_referenced_by_the_page_resolves_in_both_dictionaries(): void
    {
        $source = $this->jsx();
        $zh = $this->dictionaryKeys('zh');
        $en = $this->dictionaryKeys('en');

        preg_match_all("/'(topics\.[A-Za-z0-9_]+)'/", $source, $matches);
        $referenced = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($referenced, '頁面必須以 i18n 鍵取文案，否則本測試等於沒測。');

        foreach ($referenced as $key) {
            $this->assertContains($key, $zh, "繁中字典缺少 {$key}，使用者會看到原始鍵。");
            $this->assertContains($key, $en, "英文字典缺少 {$key}，英文介面會露出原始鍵。");
        }
    }

    /**
     * 五則必要說明缺一不可，且兩本字典都要有。
     */
    #[Test]
    public function the_five_required_notes_are_referenced_and_translated(): void
    {
        $source = $this->jsx();
        $zh = $this->dictionaryKeys('zh');
        $en = $this->dictionaryKeys('en');

        foreach (self::REQUIRED_NOTE_KEYS as $key) {
            $this->assertStringContainsString($key, $source, "頁面沒有引用必要說明 {$key}。");
            $this->assertContains($key, $zh, "繁中字典缺少必要說明 {$key}。");
            $this->assertContains($key, $en, "英文字典缺少必要說明 {$key}。");
        }
    }

    /**
     * 第五則必須把「尚未累積」與「本框架不適用」兩種「沒有答案」分開講清楚。
     *
     * 只寫「尚未累積時顯示無資料」會讓航運股（規格頭號範例題材 hormuz_oil 的核心）
     * 的使用者一直等一個永遠不會來的答案。
     */
    #[Test]
    public function the_revenue_note_distinguishes_never_from_not_yet(): void
    {
        foreach (['zh', 'en'] as $locale) {
            $note = $this->dictionary($locale)['topics']['noteRevenueUnknown'] ?? '';

            $this->assertIsString($note);
            $this->assertGreaterThan(
                40,
                mb_strlen($note),
                "{$locale} 的 noteRevenueUnknown 太短，不可能同時說明「尚未累積」與「本框架不適用」兩種情形。",
            );
        }
    }

    /**
     * 導覽列要有入口，否則整個頁面只能靠手打網址。
     */
    #[Test]
    public function the_nav_entry_exists_in_the_shell_and_in_both_dictionaries(): void
    {
        $shell = (string) file_get_contents(resource_path('js/Layouts/AppShell.jsx'));

        $this->assertStringContainsString("'nav.topics'", $shell, 'AppShell 的導覽陣列必須引用 nav.topics。');
        $this->assertStringContainsString("'/topics'", $shell, 'AppShell 的導覽陣列必須指向 /topics。');

        $this->assertContains('nav.topics', $this->dictionaryKeys('zh'));
        $this->assertContains('nav.topics', $this->dictionaryKeys('en'));
    }

    // ------------------------------------------------------------------
    // 必要說明的渲染方式
    // ------------------------------------------------------------------

    /**
     * 必要說明**無條件渲染**：不摺疊、不做 tooltip、不隨是否選了題材出現。
     *
     * 不能只斷言「不在 `<details>` 裡」——專案原本整份 JSX 一個 `<details>` 都沒有，
     * 那條斷言恆真。所以連條件渲染的 `?` 與 `&&` 一起擋：說明若只在選了題材時才
     * 出現，使用者在**決定要不要往下看**的當下就看不到更正。
     */
    #[Test]
    public function the_required_notes_render_unconditionally(): void
    {
        $body = $this->componentBody($this->jsx(), 'TopicNotes');

        foreach (['<details', '<summary', 'Collapsible', 'collapsed', 'aria-expanded', 'useState', 'title='] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                "TopicNotes 不得把必要說明藏起來（{$forbidden}）。",
            );
        }

        foreach (['?', '&&'] as $conditional) {
            $this->assertStringNotContainsString(
                $conditional,
                $body,
                "TopicNotes 不得條件渲染（{$conditional}）——必要說明要在使用者決定要不要往下看的當下就在畫面上。",
            );
        }

        foreach (self::REQUIRED_NOTE_KEYS as $key) {
            $this->assertStringContainsString($key, $body, "TopicNotes 必須渲染 {$key}。");
        }
    }

    /**
     * 說明元件本身也不得被外層條件掉。
     */
    #[Test]
    public function the_notes_component_is_mounted_outside_any_condition(): void
    {
        $source = $this->jsx();

        $this->assertMatchesRegularExpression(
            '/^\s*<TopicNotes\s*\/>\s*$/m',
            $source,
            '<TopicNotes /> 必須獨立成行掛載，不得寫成 `board ? <TopicNotes /> : null` 這種條件式。',
        );
    }

    // ------------------------------------------------------------------
    // 營收驗證徽章：四態
    // ------------------------------------------------------------------

    /**
     * 七個呈現狀態各走一個分支。
     *
     * 只斷言「有出現某個字串」對「幾者長得一樣」完全無感，所以這裡把元件主體依
     * `return (` 切成七段，逐一比對 className 與 i18n 鍵**只出現在自己那一段**。
     *
     * 五種「沒有結論」**絕對不能合併**，因為它們對使用者是五種不同的行動：
     * 「序列尚未累積」與「資料不足以判定」等分析跑過就可能有答案；「序列過舊或
     * 缺關鍵科目」要等下一次財報；「未建立標的」要先有人搜尋或 ingest 建立；
     * 「不適用此產業」永遠不會有答案。合併等於叫使用者一直等一個不會來的東西。
     */
    #[Test]
    public function the_revenue_badge_gives_every_state_its_own_branch(): void
    {
        $body = $this->componentBody($this->jsx(), 'RevenueBadge');

        $offsets = [];
        $cursor = 0;

        while (($found = strpos($body, 'return (', $cursor)) !== false) {
            $offsets[] = $found;
            $cursor = $found + 8;
        }

        $states = [
            'verified=true' => ['topic-badge--verified', 'topics.revenueVerified'],
            'verified=false' => ['topic-badge--refuted', 'topics.revenueRefuted'],
            'reason=not_applicable' => ['topic-badge--not-applicable', 'topics.revenueNotApplicable'],
            'reason=not_in_universe' => ['topic-badge--not-in-universe', 'topics.revenueNotInUniverse'],
            'reason=not_yet' => ['topic-badge--not-yet', 'topics.revenueNotYet'],
            'reason=stale' => ['topic-badge--stale', 'topics.revenueStale'],
            'reason=indeterminate' => ['topic-badge--indeterminate', 'topics.revenueIndeterminate'],
        ];

        $this->assertCount(
            count($states),
            $offsets,
            'RevenueBadge 必須讓每個狀態各走一個渲染分支：已驗證／未獲驗證，加上五種「沒有結論」的原因。',
        );

        $segments = [];

        foreach ($offsets as $index => $start) {
            $end = $offsets[$index + 1] ?? strlen($body);
            $segments[] = substr($body, $start, $end - $start);
        }

        foreach ($states as $state => [$className, $messageKey]) {
            $withClass = array_values(array_filter($segments, fn (string $s): bool => str_contains($s, $className)));
            $withKey = array_values(array_filter($segments, fn (string $s): bool => str_contains($s, $messageKey)));

            $this->assertCount(1, $withClass, "{$state} 必須有自己的 className {$className}，且只出現在一個分支。");
            $this->assertCount(1, $withKey, "{$state} 必須有自己的文案鍵 {$messageKey}，且只出現在一個分支。");
            $this->assertSame($withClass[0], $withKey[0], "{$state} 的 className 與文案鍵必須在同一個分支。");
        }

        // 原因要真的被讀，否則五個原因分支永遠走不到。
        $this->assertStringContainsString(
            'reason',
            $body,
            'RevenueBadge 必須看 revenue_unknown_reason，否則「為什麼沒有結論」永遠不會顯示。',
        );
        $this->assertStringContainsString(
            'revenue_unknown_reason',
            $this->jsx(),
            '頁面必須把 revenue_unknown_reason 傳進徽章。',
        );
    }

    /**
     * 每個後端會產生的原因，前端都要有對應的比較與文案。
     *
     * 上一條測的是「JSX 裡的分支互不重疊」，這條測的是**兩邊的列舉對得起來**：
     * 後端新增一個原因而前端沒跟上時，那一列會靜靜掉進最後一個分支被講成別的
     * 意思，而純粹掃 JSX 的斷言對這件事完全無感。
     *
     * 唯一允許沒有自己比較式的是 `indeterminate`——它是最後一個分支，同時是
     * 任何未預期值的落點（「沒有結論」是所有情形的誠實上位描述）。這條測試把
     * 「哪一個可以沒有比較式」寫死，多一個少一個都會紅。
     */
    #[Test]
    public function every_backend_reason_has_a_comparison_and_a_translation(): void
    {
        $body = $this->componentBody($this->jsx(), 'RevenueBadge');
        $zh = $this->dictionaryKeys('zh');
        $en = $this->dictionaryKeys('en');

        preg_match_all("/reason === '([a-z_]+)'/", $body, $matches);
        $compared = $matches[1];

        $this->assertSame($compared, array_values(array_unique($compared)), '同一個原因不得比較兩次。');

        $all = array_map(fn (RevenueUnknownReason $r): string => $r->value, RevenueUnknownReason::cases());

        $this->assertSame(
            [RevenueUnknownReason::Indeterminate->value],
            array_values(array_diff($all, $compared)),
            '除了 indeterminate（最後一個分支，兼未預期值的落點）以外，每個後端原因都要有自己的比較式。',
        );
        $this->assertSame(
            [],
            array_values(array_diff($compared, $all)),
            'RevenueBadge 比較了一個後端不會產生的原因，那個分支永遠走不到。',
        );

        foreach ($all as $value) {
            $key = 'topics.revenue'.str_replace('_', '', ucwords($value, '_'));

            $this->assertContains($key, $zh, "繁中字典缺少 {$key}。");
            $this->assertContains($key, $en, "英文字典缺少 {$key}。");
        }
    }

    // ------------------------------------------------------------------
    // 前端不重算判定
    // ------------------------------------------------------------------

    /**
     * 分組只用 `tier` 與 `direction` 兩個欄位，**不重算任何判定**。
     *
     * 前端自己再篩一次門檻，會與後端的判定各自漂移——階段 4 的最終審查抓到過
     * 同一份資料被兩把尺量出互相矛盾分類的案例。門檻與方向只准顯示，不准比較。
     */
    #[Test]
    public function the_page_never_recomputes_the_thresholds(): void
    {
        $source = $this->jsx();

        $forbidden = [
            '/mention_count\s*(>=|<=|>|<|===|!==|==|!=)/' => '前端不得拿 mention_count 做比較——門檻是後端 min_mentions 的事。',
            '/(>=|<=|>|<)\s*[\w.\[\]]*min_mentions/' => '前端不得拿 min_mentions 做比較，只准顯示。',
            '/min_mentions\s*(>=|<=|>|<|===|!==)/' => '前端不得拿 min_mentions 做比較，只准顯示。',
            '/direction\s*(>=|<=|>|<|===|!==|==|!=)/' => '方向只准當分組用的欄位鍵，不得在前端做判定。',
        ];

        foreach ($forbidden as $pattern => $message) {
            $this->assertDoesNotMatchRegularExpression($pattern, $source, $message);
        }

        // 分組確實用了這兩個欄位（否則上面幾條恆真）。
        $this->assertStringContainsString('.tier', $source, '分組必須用 tier 欄位。');
        $this->assertStringContainsString('.direction', $source, '分組必須用 direction 欄位。');
    }

    // ------------------------------------------------------------------
    // 必須呈現的內容
    // ------------------------------------------------------------------

    /**
     * 外圍層唯一的依據就是「被提及幾次」。拿掉它，那一層就變成一份沒有理由的清單。
     */
    #[Test]
    public function periphery_rows_show_the_mention_count(): void
    {
        $row = $this->componentBody($this->jsx(), 'CandidateRow');

        $this->assertStringContainsString(
            'mention_count',
            $row,
            '候選列必須印出 mention_count——外圍層唯一的依據就是被提及幾次。',
        );
        $this->assertStringContainsString(
            'topics.mentionCount',
            $row,
            '提及次數要有文案鍵，不能只印一個裸數字。',
        );
    }

    /**
     * 門檻藏起來，使用者無從判斷這份清單有多寬鬆。
     */
    #[Test]
    public function the_window_and_the_threshold_are_on_screen(): void
    {
        $source = $this->jsx();

        $this->assertStringContainsString('window_days', $source, '必須寫出新聞視窗天數。');
        $this->assertStringContainsString('min_mentions', $source, '必須寫出共同提及門檻。');
        $this->assertStringContainsString('topics.thresholdNote', $source);
    }

    /**
     * 傳導鏈逐句列出：使用者要看得出這個因果假設長什麼樣，才能判斷要不要信。
     */
    #[Test]
    public function the_chain_is_rendered_sentence_by_sentence(): void
    {
        $source = $this->jsx();

        $this->assertStringContainsString('board.chain', $source, '必須逐句列出 chain。');
        $this->assertStringContainsString('topics.chainLabel', $source);
    }

    /**
     * 清單為空時要說明原因，不是一片空白——而且**只能說真的會發生的原因**。
     *
     * 舊文案寫「傳導表列名的個股也還沒有可列的資料」，描述的是一個不存在的
     * 過濾行為：`core()` 對傳導表列名的標的無條件列出，缺序列也照樣是一列。
     * 空 board 真正的兩個成因是「共同提及都沒到門檻」與「傳導表沒有列出任何
     * 個股」（沒列，或列的全是指數／ETF）。裸的 assertStringContainsString
     * 對這件事完全無感，所以逐個關鍵詞比對。
     */
    #[Test]
    public function the_empty_state_only_describes_what_can_actually_happen(): void
    {
        $this->assertStringContainsString('topics.emptyCandidates', $this->jsx());

        $required = [
            'zh' => ['共同提及', '傳導表', '個股'],
            'en' => ['co-mention', 'transmission table', 'stock'],
        ];

        foreach ($required as $locale => $keywords) {
            $copy = $this->dictionary($locale)['topics']['emptyCandidates'] ?? '';

            $this->assertIsString($copy);

            foreach ($keywords as $keyword) {
                $this->assertStringContainsString(
                    $keyword,
                    $copy,
                    "{$locale} 的 emptyCandidates 必須寫出空清單的兩個真實成因，「{$keyword}」缺一不可。",
                );
            }
        }

        $this->assertStringNotContainsString(
            '可列的資料',
            (string) $this->dictionary('zh')['topics']['emptyCandidates'],
            '傳導表列名的標的不會因為「沒有資料」而被過濾掉，文案不得描述這個不存在的行為。',
        );
        $this->assertStringNotContainsString(
            'listable data',
            (string) $this->dictionary('en')['topics']['emptyCandidates'],
            '傳導表列名的標的不會因為「沒有資料」而被過濾掉，文案不得描述這個不存在的行為。',
        );
    }

    // ------------------------------------------------------------------
    // 端到端：Inertia 真的解得到這個元件
    // ------------------------------------------------------------------

    /**
     * 元件檔案存在且路徑正確。`component()` 的第二個參數維持預設（true），
     * Inertia 會實際 find 這個檔——這是 Task 4 只能傳 false 的那條斷言的完整版。
     */
    #[Test]
    public function the_route_resolves_the_page_component(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/topics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Topics/Index')->etc());
    }
}
