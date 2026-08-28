<?php

namespace Tests\Feature\News;

use App\Services\News\ArrayTransmissionRuleProvider;
use Tests\TestCase;

class ArrayTransmissionRuleProviderTest extends TestCase
{
    public function test_returns_the_given_rules_unchanged(): void
    {
        $rules = [[
            'key' => 'demo',
            'label' => '示範',
            'when' => ['keywords' => ['x'], 'domains' => []],
            'chain' => ['一'],
            'sectors' => [['name' => '甲', 'direction' => 'positive', 'symbols' => ['2330.TW']]],
        ]];

        $this->assertSame($rules, (new ArrayTransmissionRuleProvider($rules))->rules());
    }

    public function test_locale_argument_does_not_change_the_output(): void
    {
        // 陣列來源已經是最終形狀（測試 fixture、管理頁試跑的表單內容），
        // 沒有雙語欄位可解析，因此 locale 只是為了符合介面。
        $rules = [['key' => 'demo', 'label' => '示範', 'when' => [], 'chain' => [], 'sectors' => []]];
        $provider = new ArrayTransmissionRuleProvider($rules);

        $this->assertSame($provider->rules('zh'), $provider->rules('en'));
    }

    public function test_reindexes_a_sparse_input_array(): void
    {
        // 消費端一律以 foreach 走訪，但形狀契約是 list；來源若被 filter 過會留下空隙。
        $sparse = [1 => ['key' => 'a'], 5 => ['key' => 'b']];

        $this->assertSame([['key' => 'a'], ['key' => 'b']], (new ArrayTransmissionRuleProvider($sparse))->rules());
    }
}
