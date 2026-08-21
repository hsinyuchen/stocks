<?php

namespace Tests\Unit;

use App\Contracts\YieldCurveProvider;
use App\Data\YieldCurveData;
use App\Services\Rates\RatesNarrative;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RatesNarrativeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** 綁定殖利率上行（bear）且利差擴大（steepening）的曲線。 */
    private function bindBearSteepening(): void
    {
        $long = [];
        $short = [];

        for ($i = 0; $i < 130; $i++) {
            $date = sprintf('2026-%02d-%02d', 1 + intdiv($i, 28), ($i % 28) + 1);
            $long[$date] = 4.00 + $i * 0.01;
            $short[$date] = 3.00;
        }

        $curve = YieldCurveData::aligned(['10y' => $long, '3m' => $short]);

        $this->app->bind(YieldCurveProvider::class, fn () => new class($curve) implements YieldCurveProvider
        {
            public function __construct(private readonly YieldCurveData $curve) {}

            public function curve(array $tenors, int $days): YieldCurveData
            {
                return $this->curve;
            }
        });
    }

    public function test_block_states_both_windows(): void
    {
        $this->bindBearSteepening();

        $narrative = app(RatesNarrative::class);
        $block = $narrative->block($narrative->snapshot('tw'), 'zh');

        $this->assertStringContainsString('20 日', $block);
        $this->assertStringContainsString('60 日', $block);
        $this->assertStringContainsString('bp', $block);
    }

    public function test_block_says_unavailable_instead_of_inventing_values(): void
    {
        $this->app->bind(YieldCurveProvider::class, fn () => new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                return YieldCurveData::empty();
            }
        });

        $narrative = app(RatesNarrative::class);
        $block = $narrative->block($narrative->snapshot('tw'), 'zh');

        $this->assertStringContainsString('無法取得', $block);
        // 絕不以舊值或假值冒充。
        $this->assertStringNotContainsString('bp', $block);
    }

    public function test_english_locale_produces_english_regime_narrative(): void
    {
        $this->bindBearSteepening();

        $narrative = app(RatesNarrative::class);
        $block = $narrative->block($narrative->snapshot('us'), 'en');

        // 只斷言由本類別產生的敘述（表頭與窗口行）為英文。板塊名與機制說明
        // 來自 config 傳導表，其英文化由 Task 13 處理——此處不做斷言，
        // 以免用一個碰巧成立的斷言掩蓋尚未英文化的事實。
        $this->assertStringContainsString('20d window', $block);
        $this->assertStringContainsString('spread', $block);
        $this->assertStringNotContainsString('日窗口', $block);
    }

    /**
     * chainLines() 的表頭行早就依 locale 分支，但板塊行（縮排兩格的 `- 名稱：方向（why）`）
     * 一路是字面全形標點、不隨 locale 分支，英文報告因此夾雜全形冒號／括號
     * （見 finding #5）。只斷言標頭與窗口行的既有測試看不出這個問題。
     */
    public function test_english_sector_lines_have_no_full_width_punctuation_or_han_characters(): void
    {
        $this->bindBearSteepening();

        $narrative = app(RatesNarrative::class);
        // snapshot() 的 locale 決定板塊名／why 走 mapper 的哪套翻譯，block() 的
        // locale 只管表頭與窗口行；兩者都要是 'en'，否則板塊行仍是 snapshot()
        // 預設 'zh' 帶出來的中文欄位，不是本測試要驗證的全形標點分支。
        $block = $narrative->block($narrative->snapshot('us', 'en'), 'en');

        $sectorLines = array_values(array_filter(
            explode("\n", $block),
            static fn (string $line): bool => str_starts_with($line, '  - '),
        ));

        $this->assertNotEmpty($sectorLines, '英文 us 板塊區塊應至少有一行板塊敘述');

        foreach ($sectorLines as $line) {
            $this->assertSame(0, preg_match('/\p{Han}/u', $line), "英文板塊行不應含漢字：{$line}");
            $this->assertSame(0, preg_match('/[：（）]/u', $line), "英文板塊行不應含全形標點：{$line}");
        }
    }

    public function test_block_carries_the_mechanism_not_just_a_direction(): void
    {
        $this->bindBearSteepening();

        $narrative = app(RatesNarrative::class);
        $block = $narrative->block($narrative->snapshot('tw'), 'zh');

        // why 欄位必須進到 prompt，否則模型只拿到方向會自行編造理由。
        $this->assertStringContainsString('外資', $block);
    }

    public function test_affected_intersects_user_symbols_with_the_transmission_table(): void
    {
        $this->bindBearSteepening();

        $narrative = app(RatesNarrative::class);
        $snapshot = $narrative->snapshot('tw');

        $affected = $narrative->affected($snapshot, ['2330.TW', '1101.TW', '2881.TW']);

        $symbols = array_column($affected, 'symbol');
        $this->assertContains('2330.TW', $symbols);
        $this->assertContains('2881.TW', $symbols);
        // 未在傳導表內的標的不得出現。
        $this->assertNotContains('1101.TW', $symbols);
    }

    public function test_affected_preserves_mixed_direction(): void
    {
        $this->bindBearSteepening();

        $narrative = app(RatesNarrative::class);
        $affected = $narrative->affected($narrative->snapshot('tw'), ['2881.TW']);

        $this->assertSame('mixed', $affected[0]['direction']);
        $this->assertNotSame('', $affected[0]['why']);
    }

    public function test_affected_is_empty_when_regime_unavailable(): void
    {
        $this->app->bind(YieldCurveProvider::class, fn () => new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                return YieldCurveData::empty();
            }
        });

        $narrative = app(RatesNarrative::class);

        $this->assertSame([], $narrative->affected($narrative->snapshot('tw'), ['2330.TW']));
    }

    public function test_for_audience_labels_hits_by_audience(): void
    {
        $this->bindBearSteepening();

        $narrative = app(RatesNarrative::class);

        $watchlist = $narrative->forAudience('tw', ['2330.TW'], 'watchlist');
        $basket = $narrative->forAudience('tw', ['2330.TW'], 'basket');

        $this->assertStringContainsString('自選股命中 2330.TW', $watchlist['block']);
        $this->assertStringContainsString('籃子命中 2330.TW', $basket['block']);
        $this->assertTrue($watchlist['available']);
        $this->assertSame('電子權值', $watchlist['affected'][0]['sector']);
    }

    public function test_for_audience_uses_english_labels_under_en_locale(): void
    {
        $this->bindBearSteepening();

        $result = app(RatesNarrative::class)->forAudience('tw', ['2330.TW'], 'basket', 'en');

        $this->assertStringContainsString('Basket hit 2330.TW', $result['block']);
    }

    public function test_for_audience_swallows_upstream_failure(): void
    {
        // 利率只是其中一個維度，上游掛掉不該讓整份報告產不出來。
        $this->app->bind(YieldCurveProvider::class, fn () => new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                throw new \RuntimeException('upstream down');
            }
        });

        $result = app(RatesNarrative::class)->forAudience('tw', ['2330.TW']);

        $this->assertFalse($result['available']);
        $this->assertSame([], $result['affected']);
        $this->assertStringContainsString('無法取得', $result['block']);
    }

    public function test_for_audience_reports_unavailable_without_hits(): void
    {
        $this->app->bind(YieldCurveProvider::class, fn () => new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                return YieldCurveData::empty();
            }
        });

        $result = app(RatesNarrative::class)->forAudience('tw', ['2330.TW']);

        $this->assertFalse($result['available']);
        $this->assertStringContainsString('無法取得', $result['block']);
    }
}
