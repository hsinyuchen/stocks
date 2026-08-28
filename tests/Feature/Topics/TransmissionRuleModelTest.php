<?php

namespace Tests\Feature\Topics;

use App\Models\TransmissionRule;
use App\Models\TransmissionSector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransmissionRuleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_columns_round_trip_as_arrays(): void
    {
        $rule = TransmissionRule::create([
            'key' => 'demo_topic',
            'label' => '示範題材',
            'keywords' => ['甲', 'bravo'],
            'domains' => ['tech'],
            'chain' => ['第一步', '第二步'],
            'direction_cues' => ['forward' => ['漲'], 'reverse' => ['跌']],
        ]);

        $fresh = $rule->fresh();

        $this->assertSame(['甲', 'bravo'], $fresh->keywords);
        $this->assertSame(['forward' => ['漲'], 'reverse' => ['跌']], $fresh->direction_cues);
        // 未指定時的預設值：origin 為 manual、啟用、排序 0。
        $this->assertSame('manual', $fresh->origin);
        $this->assertTrue($fresh->is_active);
        $this->assertSame(0, $fresh->sort_order);
    }

    public function test_sectors_are_ordered_by_sort_order_then_id(): void
    {
        $rule = TransmissionRule::create([
            'key' => 'demo_order',
            'label' => '排序',
            'keywords' => ['x'],
            'domains' => [],
            'chain' => ['一'],
        ]);

        // 刻意讓兩筆 sort_order 相同：順序必須由 id 決定，不能是資料庫回傳的偶然順序。
        $second = TransmissionSector::create([
            'transmission_rule_id' => $rule->id,
            'name' => '乙',
            'direction' => 'neutral',
            'symbols' => [],
            'sort_order' => 0,
        ]);
        $third = TransmissionSector::create([
            'transmission_rule_id' => $rule->id,
            'name' => '丙',
            'direction' => 'neutral',
            'symbols' => [],
            'sort_order' => 0,
        ]);
        $first = TransmissionSector::create([
            'transmission_rule_id' => $rule->id,
            'name' => '甲',
            'direction' => 'positive',
            'symbols' => ['2330.TW'],
            'sort_order' => -1,
        ]);

        $names = $rule->fresh()->sectors->pluck('name')->all();

        $this->assertSame(['甲', '乙', '丙'], $names);
        $this->assertSame([$first->id, $second->id, $third->id], $rule->fresh()->sectors->pluck('id')->all());
    }

    public function test_deleting_a_rule_cascades_to_sectors(): void
    {
        $rule = TransmissionRule::create([
            'key' => 'demo_cascade',
            'label' => '級聯',
            'keywords' => ['x'],
            'domains' => [],
            'chain' => ['一'],
        ]);
        TransmissionSector::create([
            'transmission_rule_id' => $rule->id,
            'name' => '甲',
            'direction' => 'positive',
            'symbols' => [],
        ]);

        $rule->delete();

        $this->assertSame(0, TransmissionSector::count());
    }
}
