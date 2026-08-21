<?php

namespace Tests\Unit;

use App\Data\RatesRegimeData;
use App\Services\Rates\RatesTransmissionMapper;
use Tests\TestCase;

class RatesTransmissionLocaleTest extends TestCase
{
    private function bearSteepening(): RatesRegimeData
    {
        return new RatesRegimeData(
            available: true,
            longYield: 4.5,
            shortYield: 3.5,
            spreadBp: 100.0,
            windows: ['20d' => [
                'days' => 20, 'level' => 'bear', 'shape' => 'steepening',
                'quadrant' => 'bear_steepening', 'delta_level_bp' => 20.0, 'delta_shape_bp' => 20.0,
            ]],
            asOf: '2026-08-20',
        );
    }

    public function test_english_locale_yields_english_sector_names(): void
    {
        $chains = (new RatesTransmissionMapper)->map($this->bearSteepening(), 'us', 'en');

        $names = array_column($chains[0]['sectors'], 'name');

        $this->assertContains('Banks', $names);
        foreach ($names as $name) {
            $this->assertSame(0, preg_match('/\p{Han}/u', $name), "英文板塊名不應含漢字：{$name}");
        }
    }

    public function test_english_locale_yields_english_mechanism_and_chain(): void
    {
        $chains = (new RatesTransmissionMapper)->map($this->bearSteepening(), 'us', 'en');

        foreach ($chains[0]['chain'] as $step) {
            $this->assertSame(0, preg_match('/\p{Han}/u', $step), "英文傳導鏈不應含漢字：{$step}");
        }

        foreach ($chains[0]['sectors'] as $sector) {
            $this->assertNotSame('', $sector['why']);
            $this->assertSame(0, preg_match('/\p{Han}/u', $sector['why']), "英文機制說明不應含漢字：{$sector['why']}");
        }
    }

    public function test_chinese_locale_is_unchanged(): void
    {
        $chains = (new RatesTransmissionMapper)->map($this->bearSteepening(), 'us', 'zh');

        $this->assertContains('銀行', array_column($chains[0]['sectors'], 'name'));
    }

    public function test_taiwan_table_is_translated_too(): void
    {
        $chains = (new RatesTransmissionMapper)->map($this->bearSteepening(), 'tw', 'en');

        foreach ($chains[0]['sectors'] as $sector) {
            $this->assertSame(0, preg_match('/\p{Han}/u', $sector['name']));
            $this->assertSame(0, preg_match('/\p{Han}/u', $sector['why']));
        }
    }

    public function test_every_rule_in_both_markets_has_english_fields(): void
    {
        // 漏翻一條就會讓某個利率環境下的英文報告夾雜中文，且不易被發現。
        foreach (['us', 'tw'] as $market) {
            foreach ((array) config("rates.transmission.{$market}") as $rule) {
                $this->assertArrayHasKey('chain_en', $rule, "{$market}/{$rule['key']} 缺 chain_en");
                $this->assertSameSize($rule['chain'], $rule['chain_en'], "{$market}/{$rule['key']} 的 chain_en 條數不符");

                foreach ($rule['sectors'] as $sector) {
                    $this->assertArrayHasKey('name_en', $sector, "{$market}/{$rule['key']}/{$sector['name']} 缺 name_en");
                    $this->assertArrayHasKey('why_en', $sector, "{$market}/{$rule['key']}/{$sector['name']} 缺 why_en");
                }
            }
        }
    }

    public function test_symbols_are_identical_across_locales(): void
    {
        $mapper = new RatesTransmissionMapper;

        $this->assertSame(
            $mapper->symbols($mapper->map($this->bearSteepening(), 'us', 'zh')),
            $mapper->symbols($mapper->map($this->bearSteepening(), 'us', 'en')),
        );
    }
}
