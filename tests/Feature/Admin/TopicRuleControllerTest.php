<?php

namespace Tests\Feature\Admin;

use App\Models\TransmissionRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeRule(array $overrides = []): TransmissionRule
    {
        $rule = TransmissionRule::create(array_merge([
            'key' => 'demo_rule',
            'label' => '示範',
            'keywords' => ['demo'],
            'domains' => [],
            'chain' => ['一'],
        ], $overrides));

        $rule->sectors()->create(['name' => '板塊', 'direction' => 'positive', 'symbols' => []]);

        return $rule;
    }

    public function test_non_admin_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/topics')->assertForbidden();
    }

    public function test_index_lists_rules_with_counts_and_origin(): void
    {
        $this->makeRule(['key' => 'seeded', 'origin' => 'seed']);
        $this->makeRule(['key' => 'manual', 'origin' => 'manual', 'is_active' => false]);

        $response = $this->actingAs($this->admin())->get('/admin/topics');

        $response->assertOk();
        $rules = $response->viewData('page')['props']['rules'];
        $this->assertSame(['seeded', 'manual'], array_column($rules, 'key'));
        $this->assertSame(1, $rules[0]['sector_count']);
        $this->assertSame(1, $rules[0]['keyword_count']);
        $this->assertSame('seed', $rules[0]['origin']);
        $this->assertFalse($rules[1]['is_active']);
    }

    public function test_manual_rules_can_be_deleted(): void
    {
        $rule = $this->makeRule(['origin' => 'manual']);

        $this->actingAs($this->admin())->delete("/admin/topics/{$rule->id}")->assertRedirect();

        $this->assertSame(0, TransmissionRule::count());
    }

    public function test_seeded_rules_cannot_be_deleted(): void
    {
        $rule = $this->makeRule(['origin' => 'seed']);

        $response = $this->actingAs($this->admin())->from('/admin/topics')->delete("/admin/topics/{$rule->id}");

        // 用表單錯誤而非 403：admin 有權限，這是領域規則。回 403 在 Inertia 下
        // 會變成整頁攔截，與 InstrumentController 對不可刪標的的處理也不一致。
        $response->assertRedirect('/admin/topics');
        $response->assertSessionHasErrors('rule');
        $this->assertSame(1, TransmissionRule::count());
    }
}
