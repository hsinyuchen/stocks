<?php

namespace Tests\Feature\Admin;

use App\Models\TransmissionRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicRuleUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeRule(): TransmissionRule
    {
        $rule = TransmissionRule::create([
            'key' => 'editable',
            'label' => '原標題',
            'keywords' => ['demo'],
            'domains' => [],
            'chain' => ['一'],
            'origin' => 'manual',
        ]);
        $rule->sectors()->create([
            'name' => '原板塊',
            'direction' => 'positive',
            'symbols' => [],
            'direction_source' => 'suggested',
        ]);

        return $rule->fresh();
    }

    /** @return array<string, mixed> */
    private function payload(TransmissionRule $rule, array $overrides = []): array
    {
        $sector = $rule->sectors->first();

        return array_merge([
            'label' => '新標題',
            'keywords' => ['demo'],
            'domains' => [],
            'chain' => ['一'],
            'direction_cues' => ['forward' => [], 'reverse' => []],
            'is_active' => true,
            'updated_at' => $rule->updated_at->toIso8601String(),
            'sectors' => [[
                'id' => $sector->id,
                'name' => '改過的板塊',
                'direction' => 'positive',
                'symbols' => [],
            ]],
        ], $overrides);
    }

    public function test_updates_the_rule(): void
    {
        $rule = $this->makeRule();

        $this->actingAs($this->admin())->patch("/admin/topics/{$rule->id}", $this->payload($rule))->assertRedirect();

        $this->assertSame('新標題', $rule->fresh()->label);
    }

    public function test_key_cannot_be_changed(): void
    {
        $rule = $this->makeRule();

        $this->actingAs($this->admin())->patch("/admin/topics/{$rule->id}", $this->payload($rule, ['key' => 'renamed']));

        // 不報錯、直接忽略：與 InstrumentController 對 symbol 的處理一致。
        $this->assertSame('editable', $rule->fresh()->key);
    }

    public function test_updating_an_existing_sector_keeps_its_direction_source(): void
    {
        $rule = $this->makeRule();

        $this->actingAs($this->admin())->patch("/admin/topics/{$rule->id}", $this->payload($rule));

        $sector = $rule->fresh()->sectors->first();
        $this->assertSame('改過的板塊', $sector->name);
        // 逐列 sync 而非 delete-recreate：重建會把 direction_source 洗成 human，
        // 讓機器建議的方向冒充成人工填寫的。
        $this->assertSame('suggested', $sector->direction_source);
    }

    public function test_removed_sectors_are_deleted_and_new_ones_added(): void
    {
        $rule = $this->makeRule();

        $this->actingAs($this->admin())->patch("/admin/topics/{$rule->id}", $this->payload($rule, [
            'sectors' => [['name' => '全新板塊', 'direction' => 'negative', 'symbols' => ['2330.TW']]],
        ]));

        $sectors = $rule->fresh()->sectors;
        $this->assertSame(['全新板塊'], $sectors->pluck('name')->all());
        $this->assertSame('human', $sectors->first()->direction_source);
    }

    public function test_stale_updated_at_is_rejected(): void
    {
        $rule = $this->makeRule();
        $stale = $rule->updated_at->subMinute()->toIso8601String();

        $response = $this->actingAs($this->admin())
            ->from("/admin/topics/{$rule->id}/edit")
            ->patch("/admin/topics/{$rule->id}", $this->payload($rule, ['updated_at' => $stale, 'label' => '被覆蓋的標題']));

        $response->assertSessionHasErrors('updated_at');
        // 沒有樂觀鎖，兩位管理員同時編輯必有一方被靜默蓋掉。
        $this->assertSame('原標題', $rule->fresh()->label);
    }
}
