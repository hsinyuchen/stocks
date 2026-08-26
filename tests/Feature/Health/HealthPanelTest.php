<?php

namespace Tests\Feature\Health;

use App\Enums\HealthUnavailableReason;
use App\Enums\HealthVerdict;
use App\Http\Controllers\StockSearchController;
use App\Models\ChipFlow;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Health\HealthSnapshotBuilder;
use App\Services\Health\LongTermHealthReader;
use App\Services\Health\ShortTermHealthReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Social\SocialArbitragePanelTest;
use Tests\TestCase;

/**
 * 個股頁的體質判讀面板：payload 契約 + JSX 結構契約 + 字典齊全性。
 *
 * payload 那幾條走真實路由（唯一能端到端驗證的部分）；JSX 那幾條沿用
 * {@see SocialArbitragePanelTest} 的模式對原始碼做**結構性**斷言（分支、
 * className、鍵的出現位置）——裸的 `assertStringContainsString` 對
 * 「中性與不知道長得一模一樣」完全無感，而那正是本面板最容易出的錯。
 */
class HealthPanelTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // 凍結時間：財報快取的 fetched_at 會寫入 now()，不凍結的話「請求當下」與
        // 「測試重建期望值當下」會差幾毫秒，逐欄比對的 payload 契約會隨機紅。
        $this->now = CarbonImmutable::parse('2026-08-26 09:00:00');
        $this->travelTo($this->now);
    }

    // ------------------------------------------------------------------
    // payload（真實路由）
    // ------------------------------------------------------------------

    /**
     * payload 的形狀**就是兩個 reader 與快照的 `toArray()`**，controller 不得
     * 自己重組（階段 4 的 I4 教訓：形狀各自寫在兩個 controller 裡，加欄位時
     * 只改到一邊）。
     *
     * `has('health', ...)` 內刻意**不加 `etc()`**：多一個 controller 自創的鍵
     * 就會紅，而那正是「重組形狀」最常見的長相。
     */
    #[Test]
    public function the_stock_page_carries_the_readers_own_payload_shape(): void
    {
        $instrument = $this->seedTaiwanInstrument();

        $response = $this->actingAs($this->user())->get('/stocks/search?symbol=2330.TW')->assertOk();

        // 期望值在請求之後重建：controller 在同一次請求裡會先刷新財報與籌碼快取，
        // 先建會拿到請求前的狀態。
        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, StockSearchController::HEALTH_BARS);

        // 期望值走一次 JSON round-trip：`100.0` 在 JSON 裡是 `100`，解回來是 int，
        // 而 Inertia 的斷言比的是解碼後的值。不轉的話這條會壞在序列化語意上，
        // 而不是壞在「controller 有沒有重組形狀」——那才是本條要釘的東西。
        $short = $this->asJson(app(ShortTermHealthReader::class)->read($snapshot)->toArray());
        $long = $this->asJson(app(LongTermHealthReader::class)->read($snapshot)->toArray());

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Stocks/Search')
            ->has('health', fn (Assert $health) => $health
                ->where('short', $short)
                ->where('long', $long)
                ->where('snapshot', $this->asJson($snapshot->toArray())))
            ->etc());
    }

    /**
     * 個股頁走 `cachedFor()`（零上游），所以 `cached_only` 必為 true，
     * 而那句「這份判讀可能不是最新的」是它換來的代價，必須說出口。
     *
     * 這條同時釘住入口的選擇：改成 `freshFor()` 會讓 `cached_only` 變 false，
     * 個股頁這個同步 web 請求就會去打上游——而 PHP 的 max_execution_time
     * 不是例外、`try/catch` 攔不到（階段 3 的 C1 就是這個形狀）。
     */
    #[Test]
    public function the_page_uses_the_cached_entry_point_and_says_so(): void
    {
        $this->seedTaiwanInstrument();

        $this->actingAs($this->user())
            ->get('/stocks/search?symbol=2330.TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('health.snapshot.cached_only', true)
                ->etc());

        $body = $this->functionBody('HealthPanel');

        $this->assertStringContainsString('snapshot.cached_only', $body, '取用政策要據後端旗標呈現，不得寫死。');
        $this->assertStringContainsString("t('health.cachedOnlyNote')", $body);
    }

    /** 四塊一塊都不能少——不可評估的也要留著，帶著成因。 */
    #[Test]
    public function all_four_blocks_are_carried_even_when_none_can_be_evaluated(): void
    {
        $this->seedTaiwanInstrument();

        $this->actingAs($this->user())
            ->get('/stocks/search?symbol=2330.TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('health.long.blocks', 4)
                ->etc());
    }

    // ------------------------------------------------------------------
    // JSX 結構契約
    // ------------------------------------------------------------------

    /**
     * **`Neutral` 與 `null` 必須走不同分支。**「中性」是「看過了，沒有偏向」，
     * `null` 是「不知道」——對使用者是兩種不同的行動。
     *
     * 只斷言「畫面上有出現某個字串」對「兩者長得一模一樣」完全無感，所以這裡
     * 逐分支比對 className 與 i18n 鍵，並要求四者互不相同。
     */
    #[Test]
    public function neutral_and_unavailable_render_through_different_branches(): void
    {
        $body = $this->functionBody('HealthVerdictBadge');
        $branches = $this->returnBranches($body);

        $this->assertCount(4, $branches, 'HealthVerdictBadge 必須有四個分支：三態判定各一，加上不可評估。');

        $classes = [];
        $keys = [];

        foreach ($branches as $branch) {
            $classes[] = $this->firstMatch('/className="([^"]+)"/', $branch, '分支缺少 className');
            $keys[] = $this->firstMatch("/t\('(health\.[A-Za-z0-9_.]+)'\)/", $branch, '分支缺少 i18n 鍵');
        }

        $this->assertCount(4, array_unique($classes), '四個分支的 className 必須互不相同。');
        $this->assertCount(4, array_unique($keys), '四個分支的 i18n 鍵必須互不相同。');

        foreach (HealthVerdict::cases() as $verdict) {
            $this->assertStringContainsString(
                'health-verdict--'.$verdict->value,
                $body,
                "三態判定 {$verdict->value} 要有自己的 className。",
            );
        }

        $this->assertStringContainsString(
            'health-verdict--unavailable',
            $body,
            '「不知道」要有自己的 className，不得借用中性的。',
        );

        // 中性必須是**明講的比較**：少了它，null 會沿著分支落到中性那一支，
        // 於是「不知道」被講成「看過了，沒有偏向」。
        $this->assertStringContainsString(
            "verdict === 'neutral'",
            $body,
            '中性必須明確比對，不得讓 null 落到中性分支。',
        );
    }

    /**
     * **五種不可評估成因各走一個分支、各有文案。**
     *
     * 「永遠不會有」與「等一下就有」是不同的行動：把任兩種合併，使用者要嘛
     * 一直等一個不會來的東西，要嘛放棄一個再跑一次掃描就有的東西。
     */
    #[Test]
    public function each_unavailable_reason_renders_through_its_own_branch(): void
    {
        $body = $this->functionBody('HealthUnavailableNote');
        $branches = $this->returnBranches($body);

        $this->assertCount(
            count(HealthUnavailableReason::cases()),
            $branches,
            '五種成因必須各走一個分支。',
        );

        $classes = [];
        $keys = [];

        foreach ($branches as $branch) {
            $classes[] = $this->firstMatch('/className="([^"]+)"/', $branch, '成因分支缺少 className');
            $keys[] = $this->firstMatch("/t\('(health\.[A-Za-z0-9_.]+)'\)/", $branch, '成因分支缺少 i18n 鍵');
        }

        $this->assertCount(5, array_unique($classes), '五種成因的 className 必須互不相同。');
        $this->assertCount(5, array_unique($keys), '五種成因的文案鍵必須互不相同。');

        foreach (HealthUnavailableReason::cases() as $reason) {
            $this->assertStringContainsString(
                'health-unavailable--'.str_replace('_', '-', $reason->value),
                $body,
                "成因 {$reason->value} 要有自己的 className。",
            );
        }
    }

    /**
     * **六則必要說明無條件顯示、不可摺疊。** 沿用階段 4／5a 的標準：
     * 藏進 `<details>` 或 tooltip 等於沒說。
     */
    #[Test]
    public function the_six_required_notes_render_unconditionally(): void
    {
        $body = $this->functionBody('HealthNotes');

        foreach ($this->requiredNoteKeys() as $key) {
            $this->assertStringContainsString("t('{$key}')", $body, "必要說明 {$key} 沒有被渲染。");
        }

        foreach (['<details', '<summary', 'Collapsible', 'collapsed', 'aria-expanded', 'useState', 'title='] as $collapse) {
            $this->assertStringNotContainsString($collapse, $body, "必要說明不得藏起來（{$collapse}）。");
        }

        // 一個條件運算子都不准有：有的話就有辦法讓某一則在某些情況下消失。
        $this->assertStringNotContainsString('?', $body, '必要說明不得被條件包住。');
        $this->assertStringNotContainsString('&&', $body, '必要說明不得被條件包住。');

        // 不收任何 prop：收了就能依資料決定顯不顯示。
        $this->assertStringContainsString(
            'function HealthNotes()',
            $this->jsx(),
            '必要說明元件不得接受任何 prop，否則就能依資料決定顯不顯示。',
        );

        // 面板裡那一行也不得被條件包住，且必須排在唯一的 early return 之後。
        $panel = $this->functionBody('HealthPanel');
        $guard = strpos($panel, 'if (!health)');
        $usage = strpos($panel, '<HealthNotes');

        $this->assertNotFalse($guard, 'HealthPanel 應有且只有一道「沒有判讀就整段不渲染」的守門。');
        $this->assertNotFalse($usage, 'HealthPanel 必須渲染 HealthNotes。');
        $this->assertGreaterThan($guard, $usage);

        $line = $this->lineAt($panel, $usage);
        $this->assertStringNotContainsString('?', $line, '必要說明不得只在有判讀時顯示。');
        $this->assertStringNotContainsString('&&', $line, '必要說明不得只在有判讀時顯示。');
    }

    /**
     * **前端不重算判定。** 判定全部由後端的 reader 決定，前端只把機器鍵翻成文案。
     *
     * 重算會出現「畫面顯示的」與「prompt 給 LLM 的」不一致——同一份資料兩套結論，
     * 而使用者無從得知哪一個算數。
     */
    #[Test]
    public function the_page_never_recomputes_a_verdict(): void
    {
        $region = $this->healthRegion();

        foreach ([
            // 任何拿數字去比門檻的動作。JSX 的 `<div`／`</span>` 不會命中：
            // 這裡要求運算子後面緊跟數字。
            '/[\w\)\]]\s*(<=|>=|<|>)\s*-?\d/',
            // 分位與年增這些原始量根本不該進到呈現層。
            '/percentile/i',
            '/revenue_yoy/i',
        ] as $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $region,
                '判定一律取自後端，前端不得對門檻、分位或年增做任何比較。',
            );
        }

        // 判定與成因只准以後端傳來的欄位索引。
        $block = $this->functionBody('HealthBlockRow');
        $this->assertStringContainsString('verdict={block.verdict}', $block);
        $this->assertStringContainsString('reason={block.unavailable_reason}', $block);
    }

    /**
     * **每一項都要帶自己的資料日。** 實測價格、籌碼、財報分別停在三個不同的
     * 日期；只給一個「資料日」會讓使用者以為整份判讀是同一天算的。
     */
    #[Test]
    public function every_read_carries_its_own_data_date(): void
    {
        $panel = $this->functionBody('HealthPanel');

        $this->assertStringContainsString('short.price_as_of', $panel, '技術立場要帶價格的資料日。');
        $this->assertStringContainsString('short.chip_as_of', $panel, '籌碼立場要帶籌碼的資料日。');

        $block = $this->functionBody('HealthBlockRow');
        $this->assertStringContainsString('block.as_of', $block, '四塊各自要帶自己的資料日。');

        // 日期缺席時要明講「無」，不得整段省略——省略會讓讀者以為與前一項同一天。
        $asOf = $this->functionBody('HealthAsOf');
        $this->assertStringContainsString("t('health.asOfUnknown')", $asOf);
        $this->assertStringContainsString("t('health.asOfLabel'", $asOf);
    }

    /** RSI 與量能必須當場標明未參與判定。 */
    #[Test]
    public function the_context_fields_are_labelled_as_taking_no_part_in_any_verdict(): void
    {
        $panel = $this->functionBody('HealthPanel');

        $this->assertStringContainsString('short.rsi', $panel);
        $this->assertStringContainsString('short.volume_ratio', $panel);
        $this->assertStringContainsString("t('health.contextNote')", $panel);
    }

    /**
     * 技術與籌碼並列，背離時明確標示——背離不互相抵銷是本設計的核心。
     *
     * 而且背離是**三態**：`alignment` 為 null 代表無法判定，必須走與四塊相同的
     * 「不可評估」徽章，不得印成「否」。同一頁對 `rule_signal.alignment` 的既有
     * 處理（`alignment && alignment !== 'none'`）本來就是三態，新面板不能倒退。
     */
    #[Test]
    public function the_two_stances_are_shown_side_by_side_with_a_three_state_divergence_flag(): void
    {
        $panel = $this->functionBody('HealthPanel');

        $this->assertStringContainsString('short.technical_stance', $panel);
        $this->assertStringContainsString('short.chip_stance', $panel);
        $this->assertStringContainsString('short.alignment', $panel);
        $this->assertStringContainsString("t('health.divergingYes')", $panel);
        $this->assertStringContainsString("t('health.divergingNo')", $panel);

        // 三態的第三態：null 走不可評估徽章，與「否」不同的一條分支。
        $this->assertMatchesRegularExpression(
            '/short\.alignment\s*\?/',
            $panel,
            'alignment 為 null 時必須走另一條分支，不得直接印「是／否」',
        );
        $this->assertStringContainsString('<HealthVerdictBadge verdict={null} />', $panel);
    }

    // ------------------------------------------------------------------
    // 字典齊全性
    // ------------------------------------------------------------------

    /**
     * **JSX 引用的每個 `health.*` 鍵都要在中英兩本字典裡。**
     *
     * 既有的 `I18nMessageParityTest` 只驗兩本**對稱**——兩本同時漏掉同一個鍵它
     * 照樣綠，而使用者看到的會是 `health.noteNoBacktest` 這串原始鍵。
     * 寫法沿用 `ScreenRuleNoteTest::every_note_key_resolves_in_both_dictionaries`。
     */
    #[Test]
    public function every_health_key_referenced_by_the_page_resolves_in_both_dictionaries(): void
    {
        preg_match_all("/'(health\.[A-Za-z0-9_.]+)'/", $this->jsx(), $matches);

        $referenced = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($referenced, '個股頁必須引用 health.* 文案，否則本測試等於沒測。');

        // 六則必要說明必須在被引用之列：從 JSX 拿掉一則，這裡就紅。
        foreach ($this->requiredNoteKeys() as $key) {
            $this->assertContains($key, $referenced, "必要說明 {$key} 沒有被個股頁引用。");
        }

        $zh = $this->dictionaryKeys('zh');
        $en = $this->dictionaryKeys('en');

        foreach ($referenced as $key) {
            $this->assertContains($key, $zh, "繁中字典缺少 {$key}，使用者會看到原始鍵。");
            $this->assertContains($key, $en, "英文字典缺少 {$key}，使用者會看到原始鍵。");
        }
    }

    /** 三態判定與五態成因的文案必須真的不一樣，共用一句就等於分不開。 */
    #[Test]
    public function the_verdict_and_reason_copy_is_distinct_in_both_dictionaries(): void
    {
        foreach (['zh', 'en'] as $locale) {
            $dictionary = $this->dictionary($locale)['health'];

            $verdicts = [
                $dictionary['verdictPositive'],
                $dictionary['verdictNeutral'],
                $dictionary['verdictNegative'],
                $dictionary['verdictUnavailable'],
            ];
            $this->assertCount(4, array_unique($verdicts), "{$locale}：三態判定與「不可評估」必須是四段不同的文案。");

            $reasons = [
                $dictionary['reasonNotInUniverse'],
                $dictionary['reasonNotApplicable'],
                $dictionary['reasonNotYet'],
                $dictionary['reasonStale'],
                $dictionary['reasonIndeterminate'],
            ];
            $this->assertCount(5, array_unique($reasons), "{$locale}：五種成因必須是五段不同的文案。");
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function asJson(array $value): array
    {
        return json_decode((string) json_encode($value), true);
    }

    /** @return list<string> */
    private function requiredNoteKeys(): array
    {
        return [
            'health.noteNoBacktest',
            'health.noteTechnicalCollinear',
            'health.noteDivergenceNotNetted',
            'health.noteDatesDiffer',
            'health.noteUnavailableIsNotNegative',
            'health.notePricesUnadjusted',
        ];
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    /**
     * 有行情也有籌碼的台股，讓兩個立場都算得出來。
     *
     * K 棒給滿 {@see StockSearchController::HEALTH_BARS}：少於視窗數時 KD 的
     * 播種位置會變、立場可能跨過門檻，payload 契約就會依賴測資長度而不是程式碼。
     */
    private function seedTaiwanInstrument(): Instrument
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        for ($i = StockSearchController::HEALTH_BARS; $i >= 1; $i--) {
            $close = 100.0 + (StockSearchController::HEALTH_BARS - $i) * 0.5;

            DailyPrice::query()->create([
                'instrument_id' => $instrument->id,
                'priced_at' => $this->now->subDays($i)->startOfDay(),
                'open' => $close,
                'high' => $close + 1.0,
                'low' => $close - 1.0,
                'close' => $close,
                'volume' => 1_000_000,
            ]);
        }

        for ($i = 5; $i >= 1; $i--) {
            ChipFlow::query()->create([
                'instrument_id' => $instrument->id,
                'traded_at' => $this->now->subDays($i)->startOfDay(),
                'foreign_net' => 400_000,
                'trust_net' => 0,
                'dealer_net' => 0,
                'total_net' => 400_000,
            ]);
        }

        return $instrument;
    }

    private function jsx(): string
    {
        $path = resource_path('js/Pages/Stocks/Search.jsx');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * 取出某個 top-level function 的原始碼。
     *
     * 用行首的 `}` 收尾，**不要用大括號計數**：`function Foo({ bar })` 的第一個
     * `{` 是參數解構，計數會在簽名處歸零，回傳的東西不含任何 JSX——那樣的斷言
     * 看起來在檢查渲染，實際只掃過函式簽名（本專案踩過）。
     */
    private function functionBody(string $name): string
    {
        $source = $this->jsx();
        $start = strpos($source, "function {$name}(");

        $this->assertNotFalse($start, "Search.jsx 找不到 function {$name}()。");

        $end = strpos($source, "\n}\n", $start);

        $this->assertNotFalse($end, "function {$name}() 的結尾找不到。");

        return substr($source, $start, $end - $start);
    }

    /**
     * 把一個元件的主體切成各個 `return (` 分支。
     *
     * 切分支而不是整段比對：整段裡出現過某個 className 不代表它與另一態走的是
     * 不同的路，而「兩者長得一樣」正是這裡要防的事。
     *
     * @return list<string>
     */
    private function returnBranches(string $body): array
    {
        $positions = [];
        $offset = 0;

        while (($position = strpos($body, 'return (', $offset)) !== false) {
            $positions[] = $position;
            $offset = $position + 1;
        }

        $branches = [];

        foreach ($positions as $index => $position) {
            $next = $positions[$index + 1] ?? strlen($body);
            $branches[] = substr($body, $position, $next - $position);
        }

        return $branches;
    }

    private function firstMatch(string $pattern, string $subject, string $message): string
    {
        $this->assertSame(1, preg_match($pattern, $subject, $matches), $message.'：'.trim($subject));

        return $matches[1];
    }

    private function lineAt(string $body, int $position): string
    {
        $start = strrpos(substr($body, 0, $position), "\n");
        $end = strpos($body, "\n", $position);

        return substr($body, (int) $start, ($end === false ? strlen($body) : $end) - (int) $start);
    }

    /** 判讀相關的全部前端程式碼，供「不重算」那條掃描。 */
    private function healthRegion(): string
    {
        $names = [
            'healthNumber',
            'HealthAsOf',
            'HealthReasons',
            'HealthVerdictBadge',
            'HealthUnavailableNote',
            'HealthStanceRow',
            'HealthBlockRow',
            'HealthNotes',
            'HealthPanel',
        ];

        return implode("\n", array_map(fn (string $name): string => $this->functionBody($name), $names));
    }

    /** @return array<string, mixed> */
    private function dictionary(string $locale): array
    {
        $source = (string) file_get_contents(resource_path("js/i18n/messages/{$locale}.js"));
        $start = strpos($source, '{');
        $end = strrpos($source, '}');

        $decoded = json_decode(substr($source, (int) $start, (int) $end - (int) $start + 1), true);

        $this->assertIsArray($decoded, "{$locale}.js 無法以 JSON 解析。");

        return $decoded;
    }

    /** @return list<string> */
    private function dictionaryKeys(string $locale): array
    {
        $flat = [];
        $this->flatten($this->dictionary($locale), '', $flat);

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
}
