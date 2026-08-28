<?php

namespace Tests\Feature\Topics;

use App\Services\News\DbTransmissionRuleProvider;
use Database\Seeders\TransmissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 搬遷的驗收標準：資料庫來回一趟之後，規則與搬遷前完全一樣。
 */
class TransmissionParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 遞迴正規化：關聯鍵排序、數字鍵重排。
     *
     * 比對的是內容而非撰寫順序——但只放寬「關聯陣列的鍵順序」，
     * list 的順序（chain 的句序、sectors 的排列）是有意義的，保留。
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonical(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            $value[$key] = is_array($item) ? $this->canonical($item) : $item;
        }

        if ($isList) {
            return array_values($value);
        }

        ksort($value);

        return $value;
    }

    public function test_seeded_database_reproduces_the_frozen_snapshot(): void
    {
        $this->seed(TransmissionRuleSeeder::class);

        $expected = require base_path('tests/Fixtures/transmission_rules_expected.php');
        $actual = (new DbTransmissionRuleProvider)->rules('zh');

        $this->assertSame($this->canonical($expected), $this->canonical($actual));
    }

    public function test_both_cue_bearing_rules_survive_the_round_trip(): void
    {
        $this->seed(TransmissionRuleSeeder::class);

        $rules = collect((new DbTransmissionRuleProvider)->rules('zh'))->keyBy('key');

        // 兩條都有 cues。搬遷時漏掉任何一條，跌價／升值的新聞就會被標成反向結論。
        $this->assertSame(['漲價', '調漲', '報價上揚', '缺貨', '供不應求', 'price hike', 'shortage'], $rules['memory_cycle']['direction_cues']['forward']);
        $this->assertContains('升值', $rules['twd_fx']['direction_cues']['reverse']);
    }

    public function test_rules_without_cues_omit_the_key_entirely(): void
    {
        $this->seed(TransmissionRuleSeeder::class);

        $rules = collect((new DbTransmissionRuleProvider)->rules('zh'))->keyBy('key');

        // rate_policy 刻意不設 cues。若變成 {"forward":[],"reverse":[]}，
        // TransmissionMapper 會回 unknown，整組板塊被降為中性。
        $this->assertArrayNotHasKey('direction_cues', $rules['rate_policy']);
        $this->assertArrayNotHasKey('direction_cues', $rules['hormuz_oil']);
    }
}
