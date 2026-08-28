<?php

namespace Tests\Feature\Admin;

use App\Models\NewsItem;
use App\Models\TransmissionRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicRulePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'preview_topic',
            'label' => '試跑題材',
            'keywords' => ['荷莫茲'],
            'domains' => [],
            'chain' => ['一'],
            'direction_cues' => ['forward' => [], 'reverse' => []],
            'is_active' => true,
            'sectors' => [['name' => '航運', 'direction' => 'positive', 'symbols' => []]],
        ], $overrides);
    }

    public function test_reports_matches_without_writing_anything(): void
    {
        NewsItem::create([
            'source' => 'demo',
            'title' => '荷莫茲海峽航運受阻',
            'summary' => '',
            'url' => 'https://example.com/1',
            'url_hash' => hash('sha256', 'https://example.com/1'),
            'published_at' => CarbonImmutable::now(),
            'relevant' => true,
        ]);

        $response = $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->from('/admin/topics/create')
            ->post('/admin/topics/preview', $this->payload());

        $response->assertRedirect('/admin/topics/create');
        $result = session('previewResult');
        $this->assertSame(1, $result['matched']);
        $this->assertSame(['荷莫茲海峽航運受阻'], $result['samples']);
        // 試跑不得留下任何痕跡。
        $this->assertSame(0, TransmissionRule::count());
    }

    public function test_validation_applies_to_preview_too(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/topics/preview', $this->payload(['keywords' => []]))
            ->assertSessionHasErrors('keywords');
    }

    public function test_preview_still_matches_when_form_marks_the_rule_disabled(): void
    {
        // 試跑要回答「關鍵字抓不抓得到東西」，這跟存檔後會不會生效無關；
        // 停用中的草稿一樣要能看到命中數，只是要多帶一個旗標讓畫面提醒。
        NewsItem::create([
            'source' => 'demo',
            'title' => '荷莫茲海峽航運受阻',
            'summary' => '',
            'url' => 'https://example.com/2',
            'url_hash' => hash('sha256', 'https://example.com/2'),
            'published_at' => CarbonImmutable::now(),
            'relevant' => true,
        ]);

        $response = $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/topics/preview', $this->payload(['is_active' => false]));

        $response->assertSessionHasNoErrors();
        $result = session('previewResult');
        $this->assertSame(1, $result['matched']);
        $this->assertTrue($result['rule_disabled']);
    }

    public function test_preview_flag_is_false_when_rule_is_active(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/topics/preview', $this->payload(['is_active' => true]));

        $this->assertFalse(session('previewResult')['rule_disabled']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/topics/preview', $this->payload())
            ->assertForbidden();
    }

    /**
     * 從編輯頁對既有題材（例如種子的 hormuz_oil）按試跑，且該規則本身填了
     * chain_en：這正是「調整內建題材的關鍵字、看命中率有沒有變化」的實際用法。
     *
     * 試跑路由沒有 {rule} 參數，TopicRuleRequest 目前用
     * $this->route('rule') === null 判斷是否為新增，恆為 true，導致 key 的
     * unique 規則對編輯中的既有規則也生效——這是本測試要抓的 bug。
     */
    public function test_previewing_an_existing_rule_with_chain_en_succeeds(): void
    {
        TransmissionRule::create([
            'key' => 'hormuz_oil',
            'label' => '中東衝突／荷莫茲海峽',
            'keywords' => ['荷莫茲'],
            'domains' => [],
            'chain' => ['海峽關閉推升油價'],
            'chain_en' => ['Strait closure lifts oil prices'],
            'origin' => 'seed',
        ]);

        NewsItem::create([
            'source' => 'demo',
            'title' => '荷莫茲海峽航運受阻',
            'summary' => '',
            'url' => 'https://example.com/hormuz-existing',
            'url_hash' => hash('sha256', 'https://example.com/hormuz-existing'),
            'published_at' => CarbonImmutable::now(),
            'relevant' => true,
        ]);

        $response = $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->from('/admin/topics/hormuz_oil/edit')
            ->post('/admin/topics/preview', $this->payload([
                'key' => 'hormuz_oil',
                'chain_en' => ['Strait closure lifts oil prices'],
            ]));

        $response->assertSessionHasNoErrors();
        $result = session('previewResult');
        $this->assertSame(1, $result['matched']);
    }
}
