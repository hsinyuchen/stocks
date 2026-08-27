<?php

namespace Tests\Feature\Health;

use App\Enums\AnalysisStatus;
use App\Enums\HealthUnavailableReason;
use App\Enums\HealthVerdict;
use App\Http\Controllers\StockSearchController;
use App\Models\ChipFlow;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Models\StockAnalysis;
use App\Models\User;
use App\Services\Health\HealthSnapshotBuilder;
use App\Services\Health\LongTermHealthReader;
use App\Services\Health\ShortTermHealthReader;
use App\Support\DailyDataFreshness;
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

    /**
     * **兩個立場的理由要真的傳到畫面上。**
     *
     * 這條的期望值刻意是 JSX 的字面接線，不是 payload 的值：既有的面板測試拿
     * `read($snapshot)->toArray()` 當期望值，把 `reasons={short.technical_reasons}`
     * 改成 `reasons={[]}` 完全不會紅——payload 那一端照樣有欄位，只是沒人用。
     *
     * 只給立場不給理由，使用者無從判斷可信度。
     */
    #[Test]
    public function each_stance_row_receives_its_reasons(): void
    {
        $panel = $this->functionBody('HealthPanel');

        $this->assertStringContainsString('reasons={short.technical_reasons}', $panel);
        $this->assertStringContainsString('reasons={short.chip_reasons}', $panel);

        // 收下之後要真的渲染出去，不是收了就丟。
        $this->assertStringContainsString(
            '<HealthReasons reasons={reasons} />',
            $this->functionBody('HealthStanceRow'),
        );
    }

    // ------------------------------------------------------------------
    // 新鮮度：年齡與 gate
    // ------------------------------------------------------------------

    /**
     * **裸日期不夠，兩個立場都要顯示年齡。**
     *
     * 只印「2026-07-29」要使用者自己去數那是幾個交易日前，而技術面的證據強度正是
     * 以交易日衰減的。籌碼面雖然沒有 gate，年齡照樣顯示——否則畫面上會是
     * 「技術面：資料過舊」與「籌碼面：買超」並列，看不出後者其實一樣舊。
     */
    #[Test]
    public function both_stance_rows_show_an_age_not_just_a_bare_date(): void
    {
        $panel = $this->functionBody('HealthPanel');

        $this->assertStringContainsString('ageTradingDays={short.price_age_trading_days}', $panel);
        $this->assertStringContainsString('ageTradingDays={short.chip_age_trading_days}', $panel);

        // 收下之後要真的渲染出去，不是收了就丟。
        $this->assertStringContainsString(
            'ageTradingDays={ageTradingDays}',
            $this->functionBody('HealthStanceRow'),
        );
        $this->assertStringContainsString(
            '<HealthAge tradingDays={ageTradingDays} />',
            $this->functionBody('HealthAsOf'),
        );

        // **保存下來的判讀也要接上**，而且接的是那一筆分析自己的欄位。漏掉這裡的話
        // 歷史分析旁邊只剩一個裸日期，而那正是本次要修掉的東西——那份判讀還更舊。
        $saved = $this->functionBody('AnalysisHealthRead');

        $this->assertStringContainsString('ageTradingDays={short.price_age_trading_days}', $saved);
        $this->assertStringContainsString('ageTradingDays={short.chip_age_trading_days}', $saved);
        $this->assertStringContainsString('unavailableReason={short.technical_unavailable_reason}', $saved);
    }

    /**
     * **年齡由後端算好，前端一天都不算。**
     *
     * 前端複製一份工作日計算，遲早會出現「畫面顯示 7 個交易日前」但「後端已判過
     * 期」——兩套規則對同一份資料給出互相矛盾的說法。這裡掃的是「有沒有人在 JS
     * 裡碰日期」，不是「有沒有算對」：算對的那一份也不該存在。
     */
    #[Test]
    public function the_page_never_computes_an_age_itself(): void
    {
        $region = $this->healthRegion();

        foreach (['new Date', 'getDay(', 'getDate(', 'isoWeekday', 'Date.parse', 'setDate('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $region,
                '年齡必須取自後端 payload，前端不得自行做日期或工作日計算。',
            );
        }
    }

    /**
     * **技術面過舊時渲染不可評估與成因，而且成因走既有的那條管道。**
     *
     * 文案不得在前端另寫一套：同一個成因在立場列與四塊講不同的話，使用者會以為
     * 那是兩件事。這裡釘住的是「共用 HealthUnavailableNote」這個接法。
     */
    #[Test]
    public function a_gated_technical_stance_renders_its_reason_through_the_existing_channel(): void
    {
        $panel = $this->functionBody('HealthPanel');
        $row = $this->functionBody('HealthStanceRow');

        $this->assertStringContainsString('unavailableReason={short.technical_unavailable_reason}', $panel);
        $this->assertStringContainsString('<HealthUnavailableNote reason={unavailableReason} />', $row);

        // 有成因時走成因、沒有時走理由——同一組資料不得兩段都印。
        $this->assertMatchesRegularExpression(
            '/unavailableReason\s*\?/',
            $row,
            '成因與理由必須是兩條互斥分支，照 HealthBlockRow 的形狀。',
        );

        // **籌碼面不得有成因。** 它沒有 gate，給它一個 unavailableReason 就是為一個
        // 不存在的狀態預留位置，日後很容易被接上去而沒有任何量測依據。
        // 一次而且只有一次：籌碼面沒有 gate，給它一個 unavailableReason 就是為一個
        // 不存在的狀態預留位置，日後很容易被接上去而沒有任何量測依據。
        $this->assertSame(
            1,
            substr_count($panel, 'unavailableReason={short.'),
            '只有技術立場帶成因；籌碼面沒有 gate（缺量測依據），不得接上成因。',
        );
        $this->assertSame(
            1,
            substr_count($this->functionBody('AnalysisHealthRead'), 'unavailableReason={short.'),
            '保存下來的判讀同樣只有技術立場帶成因。',
        );
    }

    /**
     * payload 端到端：價格過舊時技術立場為 null、成因為 stale，**籌碼照樣有立場**。
     *
     * 走真實路由，不手寫 payload：接線斷掉時這條才會紅。
     */
    #[Test]
    public function a_stale_price_reaches_the_page_as_an_unavailable_technical_stance(): void
    {
        $threshold = (int) config('health.technical.stale_after_trading_days');

        $this->seedTaiwanInstrument($threshold);

        $this->actingAs($this->user())
            ->get('/stocks/search?symbol=2330.TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('health.short.technical_stance', null)
                ->where('health.short.technical_unavailable_reason', 'stale')
                ->where('health.short.price_age_trading_days', $threshold)
                // 立場作廢時理由與背離一併作廢。
                ->where('health.short.technical_reasons', [])
                ->where('health.short.alignment', null)
                // **籌碼不被 gate**：同樣舊的資料照樣輸出立場，只是年齡跟著揭露。
                ->where('health.short.chip_stance', 'accumulating')
                ->etc());
    }

    /** 對照組：價格夠新時照樣有立場、沒有成因，否則上一條殺不死「恆判過期」。 */
    #[Test]
    public function a_fresh_price_still_produces_a_technical_stance(): void
    {
        $this->seedTaiwanInstrument();

        $this->actingAs($this->user())
            ->get('/stocks/search?symbol=2330.TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('health.short.technical_unavailable_reason', null)
                ->where('health.short.price_age_trading_days', 1)
                ->etc());
    }

    // ------------------------------------------------------------------
    // 歷史分析保存下來的判讀
    // ------------------------------------------------------------------

    /**
     * **每一筆歷史分析要帶著它生成當下的判讀。**
     *
     * `health_read` 自 migration 起就寫入，但在此之前沒有任何 controller、payload
     * 或 JSX 讀它——於是 migration 的 docblock 宣稱解決掉的不一致原封不動：
     * 同一個頁面同時渲染歷史分析的文字（引用生成當下的判讀）與一份**現在**用
     * `cachedFor()` 算出來的面板。
     *
     * 保存的那一份與現在算出來的必須看得出是兩份：這裡的測資刻意把 `price_as_of`
     * 設成 2020，與頁面上的即時面板差了六年。
     */
    #[Test]
    public function each_analysis_carries_the_read_it_was_generated_with(): void
    {
        $instrument = $this->seedTaiwanInstrument();
        $user = $this->user();

        $this->analysis($user, $instrument, $this->savedRead(), $this->now->subHours(2));
        $this->analysis($user, $instrument, null, $this->now->subHour());

        $this->actingAs($user)
            ->get('/stocks/search?symbol=2330.TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('analyses', 2)
                // latest() 是 created_at 倒序：較新的那筆沒有判讀。
                ->where('analyses.0.health_read', null)
                ->where('analyses.1.health_read', $this->asJson($this->savedRead()))
                // 護欄：即時面板算的是**另一份**，兩者必須看得出不同——否則
                // 「顯示保存的那一份」與「顯示重算的那一份」在本測試下不可分辨。
                ->where('health.snapshot.price_as_of', $this->now->subDay()->toDateString())
                ->etc());
    }

    /**
     * **顯示的是保存的那一份，不是重算的。** 底層資料變了，保存下來的判讀不動。
     *
     * 這正是 `health_read` 存在的理由：不保存的話，幾天後頁面顯示的是現在算出來
     * 的判讀，而歷史分析的文字仍在引用生成當下的那一份。
     */
    #[Test]
    public function the_carried_read_does_not_move_when_the_underlying_data_changes(): void
    {
        $instrument = $this->seedTaiwanInstrument();
        $user = $this->user();

        $this->analysis($user, $instrument, $this->savedRead(), $this->now->subHours(2));

        DailyPrice::query()->create([
            'instrument_id' => $instrument->id,
            'priced_at' => $this->now->startOfDay(),
            'open' => 500.0, 'high' => 505.0, 'low' => 495.0, 'close' => 500.0, 'volume' => 9_000_000,
        ]);

        $this->actingAs($user)
            ->get('/stocks/search?symbol=2330.TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analyses.0.health_read', $this->asJson($this->savedRead()))
                // 護欄：即時面板真的跟著新資料動了，否則上一條等於沒測。
                ->where('health.snapshot.price_as_of', $this->now->toDateString())
                ->etc());
    }

    /**
     * **`health_read` 為 null 的舊分析整段不顯示，不得顯示「無資料」。**
     *
     * 那些分析生成時根本沒有這個功能；印一句「不可評估」會讓使用者以為當時算過
     * 而且算不出來——那與 migration 之前不存在這個功能是兩件事。
     */
    #[Test]
    public function the_saved_read_renders_only_when_the_analysis_has_one(): void
    {
        $body = $this->functionBody('AnalysisHealthRead');

        $this->assertStringContainsString(
            'if (!healthRead)',
            $body,
            'migration 之前的分析沒有判讀，整段不得渲染。',
        );
        $this->assertStringContainsString('return null;', $body);

        // 明確標示這是「生成當下」的判讀，與頁面上的即時面板在文案上分得開。
        $this->assertStringContainsString("t('health.savedReadLabel')", $body);
        $this->assertStringNotContainsString(
            "t('health.savedReadLabel')",
            $this->functionBody('HealthPanel'),
            '即時面板不得共用「生成當下」那句標示。',
        );

        // 兩個立場、四塊判定、快照的資料日期都要在。
        $this->assertStringContainsString('short.technical_stance', $body);
        $this->assertStringContainsString('short.chip_stance', $body);
        $this->assertStringContainsString('long.blocks.map', $body);
        $this->assertStringContainsString('snapshot.price_as_of', $body);
        $this->assertStringContainsString('snapshot.chip_as_of', $body);
        $this->assertStringContainsString('snapshot.fundamentals_as_of', $body);

        // **吃的是那一筆分析自己的欄位**，不是頁面上那份即時判讀。接錯的話畫面
        // 上每一筆歷史分析旁邊都會是同一份「現在」的判讀，而那正是要修的不一致。
        $this->assertStringContainsString(
            'healthRead={analysis.health_read}',
            $this->functionBody('AnalysisHistory'),
            '分析卡片必須把該筆分析自己的 health_read 傳進去。',
        );
    }

    /** 生成當下的判讀在視覺上要與即時面板分得開，不得共用同一組 className。 */
    #[Test]
    public function the_saved_read_is_visually_separate_from_the_live_panel(): void
    {
        $body = $this->functionBody('AnalysisHealthRead');

        $this->assertStringContainsString('analysis-health-read', $body);
        $this->assertStringNotContainsString('health-panel', $body);
    }

    /**
     * 一筆已完成的分析。
     *
     * forceCreate：user_id 不在 $fillable（一律由 controller 從 auth 帶入）。
     *
     * @param  array<string, mixed>|null  $healthRead
     */
    private function analysis(User $user, Instrument $instrument, ?array $healthRead, CarbonImmutable $createdAt): StockAnalysis
    {
        return StockAnalysis::query()->forceCreate([
            'user_id' => $user->id,
            'instrument_id' => $instrument->id,
            'provider_type' => 'stub',
            'model' => 'stub-model',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Completed->value,
            // 傳陣列不傳 json_encode() 的字串：'array' cast 在寫入時會再編碼一次，
            // 餵字串進去存的就是「一個 JSON 字串的 JSON」，讀回來是字串不是陣列。
            'rule_signal' => [],
            'health_read' => $healthRead,
            'llm_output' => ['content' => 'ok'],
            'data_as_of' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * 一份**保存下來**的判讀，日期刻意停在 2020。
     *
     * 與即時面板算出來的那一份差了六年：兩者若可能相同，「顯示的是保存的那一份」
     * 這件事就無法被斷言。
     *
     * @return array<string, mixed>
     */
    private function savedRead(): array
    {
        return [
            'short' => [
                'technical_stance' => 'bearish',
                'chip_stance' => 'distributing',
                'alignment' => 'confirm',
                'technical_reasons' => ['KD 偏謹慎，K 低於 D 9.0 點。'],
                'chip_reasons' => ['近 5 日外資合計賣超 1,200 張。'],
                'rsi' => 31.5,
                'volume_ratio' => 0.8,
                'price_as_of' => '2020-01-02',
                'chip_as_of' => '2020-01-03',
            ],
            'long' => [
                'blocks' => [
                    ['block' => 'valuation', 'verdict' => 'negative', 'reasons' => ['本益比 位於自身歷史第 88 百分位'], 'as_of' => '2020-01-04', 'unavailable_reason' => null],
                    ['block' => 'return_on_equity', 'verdict' => 'positive', 'reasons' => ['股東權益報酬率 18.0%'], 'as_of' => '2020-01-04', 'unavailable_reason' => null],
                    ['block' => 'growth', 'verdict' => null, 'reasons' => [], 'as_of' => null, 'unavailable_reason' => 'not_yet'],
                    ['block' => 'quality', 'verdict' => 'neutral', 'reasons' => ['營業現金流為淨利的 0.80 倍'], 'as_of' => '2019Q4', 'unavailable_reason' => null],
                ],
                'formula_version' => '2020-01-01.1',
            ],
            'snapshot' => [
                'symbol' => '2330.TW',
                'market' => 'tw',
                'bars' => 80,
                'price_as_of' => '2020-01-02',
                'chip_as_of' => '2020-01-03',
                'fundamentals_as_of' => '2020-01-04',
                'financial_period' => '2019Q4',
                'cached_only' => false,
                'asset_type' => 'stock',
            ],
        ];
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
    private function seedTaiwanInstrument(int $ageTradingDays = 1): Instrument
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        // 最後一根 K 棒刻意可以往前推：新鮮度 gate 的兩條測試就靠這個參數分開，
        // 預設 1 個交易日（不過期）與既有測試原本的形狀相同。
        $offset = $this->tradingDaysAgoOffset($ageTradingDays);

        for ($i = StockSearchController::HEALTH_BARS; $i >= 1; $i--) {
            $close = 100.0 + (StockSearchController::HEALTH_BARS - $i) * 0.5;

            DailyPrice::query()->create([
                'instrument_id' => $instrument->id,
                'priced_at' => $this->now->subDays($i + $offset)->startOfDay(),
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
                'traded_at' => $this->now->subDays($i + $offset)->startOfDay(),
                'foreign_net' => 400_000,
                'trust_net' => 0,
                'dealer_net' => 0,
                'total_net' => 400_000,
            ]);
        }

        return $instrument;
    }

    /**
     * 讓「最後一根 K 棒在 1 個交易日前」的預設測資往前推到 $ageTradingDays 個交易日前，
     * 需要多推幾個**日曆天**。
     *
     * 逐日回推而不是套算式：與被測的
     * {@see DailyDataFreshness::tradingDayAge()} 共用同一段算式的話，
     * 兩邊一起錯也不會有人發現。
     */
    private function tradingDaysAgoOffset(int $ageTradingDays): int
    {
        $date = $this->now->startOfDay();
        $counted = 0;
        $days = 0;

        while ($counted < $ageTradingDays) {
            if ($date->isWeekday()) {
                $counted++;
            }

            $date = $date->subDay();
            $days++;
        }

        // 預設測資本來就是「昨天」，所以只回傳額外要推的天數。
        return $days - 1;
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
            'HealthAge',
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
