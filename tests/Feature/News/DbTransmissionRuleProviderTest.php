<?php

namespace Tests\Feature\News;

use App\Models\TransmissionRule;
use App\Services\News\DbTransmissionRuleProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DbTransmissionRuleProviderTest extends TestCase
{
    use RefreshDatabase;

    private function makeRule(array $overrides = [], array $sectors = []): TransmissionRule
    {
        $rule = TransmissionRule::create(array_merge([
            'key' => 'demo',
            'label' => '示範題材',
            'keywords' => ['甲', 'bravo'],
            'domains' => ['tech'],
            'chain' => ['第一步', '第二步'],
        ], $overrides));

        foreach ($sectors === [] ? [['name' => '板塊甲', 'direction' => 'positive', 'symbols' => ['2330.TW']]] : $sectors as $order => $sector) {
            $rule->sectors()->create(array_merge(['sort_order' => $order, 'symbols' => []], $sector));
        }

        return $rule;
    }

    public function test_rebuilds_the_config_shape(): void
    {
        $this->makeRule();

        $this->assertSame([[
            'key' => 'demo',
            'label' => '示範題材',
            'when' => ['keywords' => ['甲', 'bravo'], 'domains' => ['tech']],
            'chain' => ['第一步', '第二步'],
            'sectors' => [['name' => '板塊甲', 'direction' => 'positive', 'symbols' => ['2330.TW']]],
        ]], (new DbTransmissionRuleProvider)->rules());
    }

    public function test_omits_direction_cues_when_null(): void
    {
        $this->makeRule();

        $rule = (new DbTransmissionRuleProvider)->rules()[0];

        // 缺鍵與空物件在 TransmissionMapper 裡語意不同：缺鍵維持宣告方向，
        // 空物件會讓整組降為中性。這裡必須是缺鍵。
        $this->assertArrayNotHasKey('direction_cues', $rule);
    }

    public function test_includes_direction_cues_when_present(): void
    {
        $this->makeRule(['direction_cues' => ['forward' => ['漲價'], 'reverse' => ['跌價']]]);

        $this->assertSame(
            ['forward' => ['漲價'], 'reverse' => ['跌價']],
            (new DbTransmissionRuleProvider)->rules()[0]['direction_cues'],
        );
    }

    public function test_skips_inactive_rules(): void
    {
        $this->makeRule(['key' => 'active']);
        $this->makeRule(['key' => 'disabled', 'is_active' => false]);

        $this->assertSame(['active'], array_column((new DbTransmissionRuleProvider)->rules(), 'key'));
    }

    public function test_orders_by_sort_order_then_id(): void
    {
        $this->makeRule(['key' => 'b', 'sort_order' => 0]);
        $this->makeRule(['key' => 'c', 'sort_order' => 0]);
        $this->makeRule(['key' => 'a', 'sort_order' => 0]);

        // sort_order 全相同時，順序必須由 id 決定而非資料庫的偶然回傳順序。
        $this->assertSame(['b', 'c', 'a'], array_column((new DbTransmissionRuleProvider)->rules(), 'key'));
    }

    public function test_english_locale_uses_translations_and_falls_back_per_field(): void
    {
        $this->makeRule([
            'label' => '中文標題',
            'label_en' => 'English label',
            'chain' => ['中文一'],
            'chain_en' => null,
        ], [
            ['name' => '中文板塊', 'name_en' => 'English sector', 'direction' => 'positive', 'symbols' => []],
        ]);

        $rule = (new DbTransmissionRuleProvider)->rules('en')[0];

        $this->assertSame('English label', $rule['label']);
        // chain_en 沒填 → 退回中文，而不是變成空陣列。
        $this->assertSame(['中文一'], $rule['chain']);
        $this->assertSame('English sector', $rule['sectors'][0]['name']);
    }

    public function test_zh_locale_ignores_translations(): void
    {
        $this->makeRule(['label' => '中文標題', 'label_en' => 'English label']);

        $this->assertSame('中文標題', (new DbTransmissionRuleProvider)->rules('zh')[0]['label']);
    }

    public function test_admin_only_columns_are_not_exposed(): void
    {
        $this->makeRule(['curator_note' => '策展依據'], [
            ['name' => '板塊', 'direction' => 'positive', 'symbols' => [], 'direction_source' => 'suggested', 'curator_note' => '板塊依據'],
        ]);

        $rule = (new DbTransmissionRuleProvider)->rules()[0];

        foreach (['curator_note', 'origin', 'is_active', 'sort_order', 'id'] as $column) {
            $this->assertArrayNotHasKey($column, $rule);
        }
        foreach (['curator_note', 'direction_source', 'sort_order', 'id'] as $column) {
            $this->assertArrayNotHasKey($column, $rule['sectors'][0]);
        }
    }

    public function test_memoizes_within_one_instance(): void
    {
        $this->makeRule();
        $provider = new DbTransmissionRuleProvider;
        $provider->rules();

        // news:ingest 對每則新聞呼叫一次 map()，近 30 日 5010 則；
        // 沒有記憶化就是 5010 次查詢。
        DB::enableQueryLog();
        $provider->rules();
        $this->assertSame([], DB::getQueryLog());
    }
}
