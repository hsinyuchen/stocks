<?php

namespace Tests\Unit;

use App\Data\RatesRegimeData;
use App\Services\Rates\RatesTransmissionMapper;
use Tests\TestCase;

class RatesTransmissionMapperTest extends TestCase
{
    private function regime(
        string $level = 'neutral',
        string $shape = 'neutral',
        bool $inverted = false,
        bool $recentlyUninverted = false,
    ): RatesRegimeData {
        $quadrant = ($level !== 'neutral' && $shape !== 'neutral') ? $level.'_'.$shape : null;

        return new RatesRegimeData(
            available: true,
            longYield: 4.5,
            shortYield: 3.5,
            spreadBp: $inverted ? -50.0 : 100.0,
            inverted: $inverted,
            recentlyUninverted: $recentlyUninverted,
            windows: ['20d' => [
                'days' => 20,
                'level' => $level,
                'shape' => $shape,
                'quadrant' => $quadrant,
                'delta_level_bp' => 20.0,
                'delta_shape_bp' => 20.0,
            ]],
            asOf: '2026-08-20',
        );
    }

    public function test_bear_steepening_makes_us_banks_positive(): void
    {
        $chains = (new RatesTransmissionMapper)->map($this->regime('bear', 'steepening'), 'us');

        $banks = $this->sector($chains, '銀行');

        $this->assertSame('positive', $banks['direction']);
        $this->assertSame('high', $chains[0]['conviction']);
        $this->assertNotSame('', $banks['why']);
    }

    public function test_bear_flattening_makes_us_banks_negative(): void
    {
        // 與熊陡同為殖利率上行，但利差收窄，對銀行結論相反。
        // 這是選四象限而非單維趨勢的核心理由。
        $chains = (new RatesTransmissionMapper)->map($this->regime('bear', 'flattening'), 'us');

        $this->assertSame('negative', $this->sector($chains, '銀行')['direction']);
    }

    public function test_growth_stocks_are_negative_under_both_bear_quadrants(): void
    {
        $mapper = new RatesTransmissionMapper;

        foreach (['steepening', 'flattening'] as $shape) {
            $chains = $mapper->map($this->regime('bear', $shape), 'us');
            $this->assertSame(
                'negative',
                $this->sector($chains, '長天期成長股')['direction'],
                "bear_{$shape} 下長天期成長股應承壓",
            );
        }
    }

    public function test_falls_back_to_level_rule_when_shape_is_neutral(): void
    {
        $chains = (new RatesTransmissionMapper)->map($this->regime('bear', 'neutral'), 'us');

        $this->assertNotSame([], $chains);
        $this->assertSame('medium', $chains[0]['conviction']);
        $this->assertSame('negative', $this->sector($chains, '長天期成長股')['direction']);
    }

    public function test_falls_back_to_shape_rule_when_level_is_neutral(): void
    {
        $chains = (new RatesTransmissionMapper)->map($this->regime('neutral', 'steepening'), 'us');

        $this->assertSame('medium', $chains[0]['conviction']);
        $this->assertSame('positive', $this->sector($chains, '銀行')['direction']);
    }

    public function test_no_directional_rule_when_both_dimensions_neutral(): void
    {
        $chains = (new RatesTransmissionMapper)->map($this->regime('neutral', 'neutral'), 'us');

        $this->assertSame([], $chains);
    }

    public function test_at_most_one_directional_rule_fires_per_market(): void
    {
        $mapper = new RatesTransmissionMapper;

        $cases = [
            ['bear', 'steepening'], ['bear', 'flattening'],
            ['bull', 'steepening'], ['bull', 'flattening'],
            ['bear', 'neutral'], ['bull', 'neutral'],
            ['neutral', 'steepening'], ['neutral', 'flattening'],
            ['neutral', 'neutral'],
        ];

        foreach ($cases as [$level, $shape]) {
            foreach (['us', 'tw'] as $market) {
                $chains = $mapper->map($this->regime($level, $shape), $market);
                $directional = array_filter($chains, static fn (array $c): bool => $c['conviction'] !== 'reference');

                $this->assertLessThanOrEqual(
                    1,
                    count($directional),
                    "{$market} 在 {$level}/{$shape} 下命中了多於一條方向規則",
                );
            }
        }
    }

    public function test_taiwan_uses_level_rules_even_when_a_quadrant_exists(): void
    {
        // 美債對台股的傳導走「殖利率水準 → 美元 → 外資流向」，與曲線形狀無關，
        // 故台股表不定義象限規則，會自然落到 level 規則。
        $chains = (new RatesTransmissionMapper)->map($this->regime('bear', 'steepening'), 'tw');

        $this->assertNotSame([], $chains);
        $this->assertSame('medium', $chains[0]['conviction']);
        $this->assertStringContainsString('外資', implode(' ', $chains[0]['chain']));
    }

    public function test_taiwan_life_insurers_are_marked_mixed_not_directional(): void
    {
        // 台壽險持有大量美元債：殖利率上行造成既有部位評價壓力，但新資金再投資
        // 收益率上升，同時台幣貶值帶來換算利益。淨效果依帳列分類與升息階段而異，
        // 沒有可靠的單一方向，必須標 mixed。
        $chains = (new RatesTransmissionMapper)->map($this->regime('bear', 'steepening'), 'tw');

        $insurers = $this->sector($chains, '壽險金融');

        $this->assertSame('mixed', $insurers['direction']);
        $this->assertContains('2881.TW', $insurers['symbols']);
    }

    public function test_mixed_direction_is_never_collapsed_to_a_single_direction(): void
    {
        $mapper = new RatesTransmissionMapper;

        foreach ([['bear', 'steepening'], ['bull', 'flattening']] as [$level, $shape]) {
            foreach ($mapper->map($this->regime($level, $shape), 'tw') as $chain) {
                foreach ($chain['sectors'] as $sector) {
                    if ($sector['name'] === '壽險金融') {
                        $this->assertSame('mixed', $sector['direction']);
                    }
                }
            }
        }
    }

    public function test_inversion_rule_is_appended_with_reference_conviction(): void
    {
        $chains = (new RatesTransmissionMapper)->map($this->regime('bear', 'steepening', inverted: true), 'us');

        $reference = array_values(array_filter($chains, static fn (array $c): bool => $c['conviction'] === 'reference'));

        $this->assertCount(1, $reference);
        // 歷史樣本極少，文案不得表述為預測。
        $this->assertStringContainsString('參考', implode(' ', $reference[0]['chain']));
    }

    public function test_recently_uninverted_also_triggers_the_reference_rule(): void
    {
        $chains = (new RatesTransmissionMapper)->map(
            $this->regime('neutral', 'neutral', recentlyUninverted: true),
            'us',
        );

        $this->assertCount(1, $chains);
        $this->assertSame('reference', $chains[0]['conviction']);
    }

    public function test_unavailable_regime_produces_no_chains(): void
    {
        $chains = (new RatesTransmissionMapper)->map(RatesRegimeData::unavailable(), 'us');

        $this->assertSame([], $chains);
    }

    public function test_symbols_are_deduplicated_across_chains(): void
    {
        $mapper = new RatesTransmissionMapper;
        $chains = $mapper->map($this->regime('bear', 'steepening', inverted: true), 'us');

        $symbols = $mapper->symbols($chains);

        $this->assertSame(array_values(array_unique($symbols)), $symbols);
        $this->assertNotSame([], $symbols);
    }

    public function test_unknown_market_returns_no_chains(): void
    {
        $chains = (new RatesTransmissionMapper)->map($this->regime('bear', 'steepening'), 'jp');

        $this->assertSame([], $chains);
    }

    public function test_english_locale_falls_back_to_chinese_when_en_fields_are_absent(): void
    {
        // Task 13 補齊了 config 的 _en 欄位，本測試不再能用真實 config 鎖住
        // 「尚未翻譯」這個前提。改用暫時去掉 _en 欄位的 stub 規則，直接驗證
        // present() 的回退機制本身：缺 _en 時退中文，而不是留空字串——空字串
        // 會讓 prompt 與 UI 完全失去機制說明。真實 config 是否翻譯齊全，交給
        // test_every_rule_in_both_markets_has_english_fields 把關。
        config()->set('rates.transmission.us', [[
            'key' => 'stub',
            'when' => ['quadrant' => 'bear_steepening'],
            'conviction' => 'high',
            'chain' => ['中文步驟'],
            'sectors' => [[
                'name' => '銀行',
                'direction' => 'positive',
                'why' => '中文機制',
                'symbols' => ['JPM'],
            ]],
        ]]);

        $chains = (new RatesTransmissionMapper)->map($this->regime('bear', 'steepening'), 'us', 'en');

        $this->assertSame(['中文步驟'], $chains[0]['chain']);
        $this->assertSame('銀行', $chains[0]['sectors'][0]['name']);
        $this->assertSame('中文機制', $chains[0]['sectors'][0]['why']);
    }

    public function test_english_locale_prefers_en_fields_when_present(): void
    {
        config()->set('rates.transmission.us', [[
            'key' => 'stub',
            'when' => ['quadrant' => 'bear_steepening'],
            'conviction' => 'high',
            'chain' => ['中文步驟'],
            'chain_en' => ['English step'],
            'sectors' => [[
                'name' => '銀行', 'name_en' => 'Banks',
                'direction' => 'positive',
                'why' => '中文機制', 'why_en' => 'English mechanism',
                'symbols' => ['JPM'],
            ]],
        ]]);

        $chains = (new RatesTransmissionMapper)->map($this->regime('bear', 'steepening'), 'us', 'en');

        $this->assertSame(['English step'], $chains[0]['chain']);
        $this->assertSame('Banks', $chains[0]['sectors'][0]['name']);
        $this->assertSame('English mechanism', $chains[0]['sectors'][0]['why']);
        // 代號不隨語言改變。
        $this->assertSame(['JPM'], $chains[0]['sectors'][0]['symbols']);
    }

    /**
     * @param  list<array<string, mixed>>  $chains
     * @return array{name: string, direction: string, why: string, symbols: list<string>}
     */
    private function sector(array $chains, string $name): array
    {
        foreach ($chains as $chain) {
            foreach ($chain['sectors'] as $sector) {
                if ($sector['name'] === $name) {
                    return $sector;
                }
            }
        }

        $this->fail("傳導鏈中找不到板塊：{$name}");
    }
}
