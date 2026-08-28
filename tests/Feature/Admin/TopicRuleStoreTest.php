<?php

namespace Tests\Feature\Admin;

use App\Models\TransmissionRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicRuleStoreTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'new_topic',
            'label' => '新題材',
            'label_en' => '',
            'keywords' => ['Hormuz', ' hormuz ', '荷莫茲'],
            'domains' => ['energy'],
            'chain' => ['第一步', '第二步'],
            'chain_en' => [],
            'direction_cues' => ['forward' => [], 'reverse' => []],
            'curator_note' => '建立理由',
            'is_active' => true,
            'sectors' => [[
                'name' => '航運',
                'name_en' => '',
                'direction' => 'positive',
                'symbols' => ['2603.TW'],
                'curator_note' => '',
            ]],
        ], $overrides);
    }

    public function test_creates_a_manual_rule(): void
    {
        $this->actingAs($this->admin())->post('/admin/topics', $this->payload())->assertRedirect();

        $rule = TransmissionRule::where('key', 'new_topic')->firstOrFail();
        $this->assertSame('manual', $rule->origin);
        $this->assertSame('建立理由', $rule->curator_note);
        $this->assertSame(['航運'], $rule->sectors->pluck('name')->all());
        $this->assertSame('human', $rule->sectors->first()->direction_source);
    }

    public function test_keywords_are_lowercased_trimmed_and_deduplicated(): void
    {
        $this->actingAs($this->admin())->post('/admin/topics', $this->payload());

        // 'Hormuz' 與 ' hormuz ' 是同一個關鍵字。未正規化就會存成兩筆，去重失效。
        $this->assertSame(['hormuz', '荷莫茲'], TransmissionRule::where('key', 'new_topic')->value('keywords'));
    }

    public function test_empty_cues_on_both_sides_are_stored_as_null(): void
    {
        $this->actingAs($this->admin())->post('/admin/topics', $this->payload());

        // {"forward":[],"reverse":[]} 會讓 TransmissionMapper 回 unknown，
        // 整組板塊被降為中性；NULL 才是「維持宣告方向」。
        $this->assertNull(TransmissionRule::where('key', 'new_topic')->value('direction_cues'));
    }

    public function test_origin_cannot_be_set_from_the_form(): void
    {
        $this->actingAs($this->admin())->post('/admin/topics', $this->payload(['origin' => 'seed']));

        $this->assertSame('manual', TransmissionRule::where('key', 'new_topic')->value('origin'));
    }

    public function test_rejects_invalid_key_direction_and_domain(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/topics', $this->payload(['key' => 'Bad Key']))
            ->assertSessionHasErrors('key');
        $this->actingAs($admin)->post('/admin/topics', $this->payload([
            'sectors' => [['name' => 'x', 'direction' => 'sideways', 'symbols' => []]],
        ]))->assertSessionHasErrors('sectors.0.direction');
        $this->actingAs($admin)->post('/admin/topics', $this->payload(['domains' => ['nonexistent']]))
            ->assertSessionHasErrors('domains.0');

        $this->assertSame(0, TransmissionRule::count());
    }

    public function test_unknown_symbols_warn_but_do_not_block(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/topics', $this->payload([
            'sectors' => [['name' => '航運', 'direction' => 'positive', 'symbols' => ['9999.TW']]],
        ]));

        // 管理員常需要先建規則再補標的，硬擋會讓那個流程走不通；
        // 但掛上沒有行情的標的會讓使用者點進去看到空白，所以要出聲。
        $response->assertRedirect();
        $response->assertSessionHas('warning');
        $this->assertStringContainsString('9999.TW', session('warning'));
        $this->assertSame(1, TransmissionRule::count());
    }
}
