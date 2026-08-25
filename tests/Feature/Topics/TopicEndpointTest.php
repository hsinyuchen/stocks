<?php

namespace Tests\Feature\Topics;

use App\Models\User;
use App\Services\Topics\TopicCandidateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/topics` 的路由與 payload。
 *
 * 這裡釘的不是候選判定（那是 TopicCandidateResolverTest 的事），而是入口本身的
 * 三個決定：未指定／無效題材不當成錯誤、payload 形狀由 DTO 決定、`selected`
 * 只反映真正解出來的題材。
 */
class TopicEndpointTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_redirected(): void
    {
        $this->get('/topics')->assertRedirect('/login');
    }

    /**
     * 未指定題材時只列出可選項，**不預設載入任何一個**。
     */
    #[Test]
    public function without_a_topic_it_only_offers_the_choices(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/topics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // 第二個參數關掉「頁面檔案必須存在」的檢查：頁面元件是 Task 5
                // 的產出，這條測試釘的是 controller 選了哪個元件名稱，不是前端
                // 檔案有沒有落地。Task 5 落地後這裡不需要改（存在檢查只是額外
                // 保障，元件名稱本身仍然被斷言）。
                ->component('Topics/Index', false)
                ->where('board', null)
                ->where('selected', null)
                ->has('topics', 8)
                ->etc());
    }

    /**
     * 無效的 topic 回到選擇畫面，不是 404——使用者手改網址或書籤過期時，
     * 給他一個可以往下走的畫面比一頁錯誤有用。
     */
    #[Test]
    public function an_unknown_topic_falls_back_to_the_choices(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/topics?topic=no_such_topic')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board', null)->where('selected', null)->etc());
    }

    /**
     * payload 形狀與 resolver 完全一致。這條釘的不是「有沒有 board」，
     * 而是「controller 有沒有自己重組形狀」——重組就會漂移。
     */
    #[Test]
    public function the_payload_comes_straight_from_the_resolver(): void
    {
        $expected = app(TopicCandidateResolver::class)->resolve('hormuz_oil')?->toArray();

        $this->assertNotNull($expected, 'resolver 對已知題材必須回 board，否則本測試等於沒測');

        $this->actingAs(User::factory()->create())
            ->get('/topics?topic=hormuz_oil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('board', $expected)
                ->where('selected', 'hormuz_oil')
                ->etc());
    }

    #[Test]
    public function the_topic_choices_match_the_transmission_config(): void
    {
        $expected = app(TopicCandidateResolver::class)->availableTopics();

        $this->actingAs(User::factory()->create())
            ->get('/topics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('topics', $expected)->etc());
    }
}
