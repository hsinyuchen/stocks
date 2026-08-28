<?php

namespace Tests\Feature\Topics;

use App\Contracts\TransmissionRuleProvider;
use App\Models\NewsItem;
use App\Models\TransmissionRule;
use App\Models\User;
use App\Services\News\DbTransmissionRuleProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    /**
     * 迴歸測試：NewsController::itemPayload() 曾經（審查抓到的變異）把傳給
     * TransmissionMapper::map() 的語系引數硬編碼成 'zh'，導致新聞頁的傳導區塊
     * 永遠不會跟著使用者語系切換，而當時所有測試仍全綠。這裡直接斷言
     * en 使用者看到的 transmission 標籤與板塊名稱是英文。
     */
    public function test_news_page_transmission_uses_the_viewer_locale(): void
    {
        $this->seedBilingualRule();
        $user = User::factory()->create();
        $user->profile()->update(['locale' => 'en']);

        NewsItem::create([
            'source' => '測試來源',
            'title' => 'Demo 事件測試新聞',
            'summary' => '測試摘要內容',
            'url' => 'https://example.com/news-locale-demo',
            'published_at' => now(),
            'language' => 'zh-TW',
            'market' => 'TW',
            'topic' => 'macro',
            'relevant' => true,
        ]);

        $this->actingAs($user)
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.transmission.0.label', 'Bilingual topic')
                ->where('items.data.0.transmission.0.sectors.0.name', 'English sector'));
    }

    /**
     * 迴歸測試：DashboardController::transmissionFocus() / buildPayload() 曾經
     * （審查抓到的變異）把語系引數硬編碼成 'zh'，儀表板的 transmissionFocus
     * 永遠不跟著使用者語系走，而當時所有測試仍全綠。transmissionFocus 是
     * deferred prop，須走 getDashboard() 的 partial 請求才會出現在 response。
     */
    public function test_dashboard_transmission_focus_uses_the_viewer_locale(): void
    {
        $this->seedBilingualRule();
        $user = User::factory()->create();
        $user->profile()->update(['locale' => 'en']);

        NewsItem::create([
            'source' => '測試來源',
            'title' => 'Demo 事件測試新聞',
            'summary' => '測試摘要內容',
            'url' => 'https://example.com/dashboard-locale-demo',
            'published_at' => now(),
            'language' => 'zh-TW',
            'market' => 'TW',
            'topic' => 'macro',
            'relevant' => true,
        ]);

        $chains = $this->actingAs($user)->getDashboard()->assertOk()
            ->json('props.transmissionFocus');

        $this->assertNotEmpty($chains, '應命中種好的雙語傳導規則。');
        $this->assertSame('Bilingual topic', $chains[0]['label']);
        $this->assertSame('English sector', $chains[0]['sectors'][0]['name']);
    }
}
