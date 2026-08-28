<?php

namespace Tests\Feature\Topics;

use App\Contracts\TransmissionRuleProvider;
use App\Models\TransmissionRule;
use App\Models\User;
use App\Services\News\DbTransmissionRuleProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransmissionLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function seedBilingualRule(): void
    {
        // 這個測試要驗的是 DB 來源的雙語解析，所以改綁 DB provider。
        $this->app->instance(TransmissionRuleProvider::class, new DbTransmissionRuleProvider);

        TransmissionRule::create([
            'key' => 'bilingual_demo',
            'label' => '雙語題材',
            'label_en' => 'Bilingual topic',
            'keywords' => ['demo'],
            'domains' => [],
            'chain' => ['中文鏈'],
        ])->sectors()->create([
            'name' => '中文板塊',
            'name_en' => 'English sector',
            'direction' => 'positive',
            'symbols' => [],
        ]);
    }

    public function test_topics_page_uses_the_viewer_locale(): void
    {
        $this->seedBilingualRule();
        $user = User::factory()->create();
        // User::booted() 的 created 事件會在建立 user 時自動建立 profile
        // （見 app/Models/User.php），UserFactory 本身沒有另外的 afterCreating，
        // 但 profile 已存在，所以這裡用 update() 即可，不需要再 create()。
        $user->profile()->update(['locale' => 'en']);

        $response = $this->actingAs($user)->get('/topics');

        $response->assertOk();
        $labels = array_column($response->viewData('page')['props']['topics'], 'label');
        $this->assertSame(['Bilingual topic'], $labels);
    }

    public function test_topics_page_falls_back_to_chinese(): void
    {
        $this->seedBilingualRule();
        $user = User::factory()->create();
        $user->profile()->update(['locale' => 'zh']);

        $response = $this->actingAs($user)->get('/topics');

        $labels = array_column($response->viewData('page')['props']['topics'], 'label');
        $this->assertSame(['雙語題材'], $labels);
    }
}
