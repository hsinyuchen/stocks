<?php

namespace Tests\Feature\Admin;

use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /admin/instruments 的分頁。
 *
 * 後端一直都有 paginate()，但前端沒有渲染 links——超過單頁筆數的標的就此看不見，
 * 而且畫面上完全沒有跡象。這裡鎖住 payload 必須帶齊翻頁所需的資料。
 */
class InstrumentPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function seedInstruments(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Instrument::factory()->create(['symbol' => sprintf('P%04d.TW', $i), 'market' => 'TW']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function props(User $user, string $uri = '/admin/instruments'): array
    {
        return $this->actingAs($user)->get($uri)->assertOk()->viewData('page')['props'];
    }

    public function test_payload_carries_everything_the_pager_needs(): void
    {
        $this->seedInstruments(71);

        $props = $this->props($this->admin());
        $instruments = $props['instruments'];

        $this->assertSame(50, $instruments['per_page']);
        $this->assertSame(71, $instruments['total']);
        $this->assertSame(2, $instruments['last_page']);
        $this->assertSame(1, $instruments['current_page']);
        // links 是前端翻頁列的資料來源；少了它畫面就只能顯示第一頁。
        $this->assertNotEmpty($instruments['links']);
        $this->assertCount(50, $instruments['data']);
    }

    public function test_second_page_returns_the_remainder(): void
    {
        $this->seedInstruments(71);

        $props = $this->props($this->admin(), '/admin/instruments?page=2');

        $this->assertSame(2, $props['instruments']['current_page']);
        $this->assertCount(21, $props['instruments']['data']);
    }

    public function test_filters_survive_page_changes(): void
    {
        $this->seedInstruments(60);
        Instrument::factory()->create(['symbol' => 'AAPL', 'market' => 'US']);

        $props = $this->props($this->admin(), '/admin/instruments?market=TW&page=2');

        // withQueryString() 必須把篩選帶進翻頁連結，否則翻到第二頁就變成未篩選的結果。
        $this->assertSame('TW', $props['filters']['market']);
        $this->assertSame(60, $props['instruments']['total']);
        $this->assertStringContainsString('market=TW', $props['instruments']['links'][1]['url']);
    }

    public function test_single_page_still_reports_consistent_totals(): void
    {
        $this->seedInstruments(5);

        $props = $this->props($this->admin());

        $this->assertSame(1, $props['instruments']['last_page']);
        $this->assertSame(5, $props['instruments']['total']);
        $this->assertSame(5, $props['total']);
    }
}
