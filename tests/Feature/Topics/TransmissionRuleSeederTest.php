<?php

namespace Tests\Feature\Topics;

use App\Models\TransmissionRule;
use App\Models\TransmissionSector;
use Database\Seeders\TransmissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TransmissionRuleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_every_rule_with_its_sectors(): void
    {
        $this->seed(TransmissionRuleSeeder::class);

        $this->assertSame(8, TransmissionRule::count());
        $this->assertSame(
            ['hormuz_oil', 'chip_export_control', 'memory_cycle', 'ai_capex', 'rate_policy', 'natural_disaster', 'market_shock', 'twd_fx'],
            TransmissionRule::orderBy('sort_order')->orderBy('id')->pluck('key')->all(),
        );
        $this->assertSame('seed', TransmissionRule::where('key', 'hormuz_oil')->value('origin'));

        // 每條規則都必須有板塊；只建了規則卻沒建板塊等於靜默壞掉。
        foreach (TransmissionRule::with('sectors')->get() as $rule) {
            $this->assertNotEmpty($rule->sectors, "規則 {$rule->key} 沒有板塊");
        }
    }

    public function test_carries_curator_notes_from_the_data_file(): void
    {
        $this->seed(TransmissionRuleSeeder::class);

        $ratePolicy = TransmissionRule::where('key', 'rate_policy')->firstOrFail();
        $this->assertStringContainsString('殖利率是事實', (string) $ratePolicy->curator_note);

        // TransmissionSector 的關聯方法叫 rule()，不是 whereBelongsTo() 預設猜測的
        // transmissionRule，因此需要顯式指定關聯名稱。
        $backEnd = TransmissionSector::whereBelongsTo($ratePolicy, 'rule')->get();
        $this->assertNotEmpty($backEnd);

        $memory = TransmissionRule::where('key', 'memory_cycle')->firstOrFail();
        $packaging = TransmissionSector::whereBelongsTo($memory, 'rule')->where('name', '封測')->firstOrFail();
        $this->assertStringContainsString('2311 與 2325', (string) $packaging->curator_note);
    }

    public function test_running_twice_does_not_duplicate_or_overwrite(): void
    {
        $this->seed(TransmissionRuleSeeder::class);

        $rule = TransmissionRule::where('key', 'hormuz_oil')->firstOrFail();
        $rule->update(['label' => '管理員改過的標題']);

        $this->seed(TransmissionRuleSeeder::class);

        $this->assertSame(8, TransmissionRule::count());
        // 覆蓋人工編輯是這個 seeder 最不能犯的錯：正式站跑一次 db:seed 就會毀掉策展成果。
        $this->assertSame('管理員改過的標題', TransmissionRule::where('key', 'hormuz_oil')->value('label'));
    }

    public function test_fails_loudly_when_an_existing_seed_rule_has_no_sectors(): void
    {
        // 模擬上一次 seed 中途失敗：規則在、板塊沒建成。靜默跳過會讓那條規則
        // 永遠是壞的，而且沒有任何訊號。
        TransmissionRule::create([
            'key' => 'hormuz_oil',
            'label' => '半套資料',
            'keywords' => ['x'],
            'domains' => [],
            'chain' => ['一'],
            'origin' => 'seed',
        ]);

        $this->expectException(RuntimeException::class);

        $this->seed(TransmissionRuleSeeder::class);
    }
}
