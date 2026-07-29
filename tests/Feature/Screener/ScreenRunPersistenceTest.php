<?php

namespace Tests\Feature\Screener;

use App\Models\Instrument;
use App\Models\ScreenRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenRunPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /** 掃描範圍＝標的清單（instruments 表），不再是 config 的 universe。 */
    private function universe(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha']);
        Instrument::factory()->create(['symbol' => 'BBB', 'name' => 'Beta']);
    }

    // --- 留存 ---

    public function test_scan_persists_a_run_snapshot(): void
    {
        $this->universe();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/screener/scan', ['rules' => ['above_ma20']])
            ->assertOk();

        $run = $user->screenRuns()->firstOrFail();

        $this->assertSame(['above_ma20'], $run->rules);
        $this->assertSame(2, $run->scanned);
        $this->assertSame($run->matched, count($run->results));
        $this->assertSame($run->id, $response->json('run_id'));
    }

    public function test_excluded_rules_are_recorded_on_the_run(): void
    {
        $this->universe();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/screener/scan', [
            'rules' => ['above_ma20'],
            'exclude' => ['rsi_overbought'],
        ])->assertOk();

        $this->assertSame(['rsi_overbought'], $user->screenRuns()->firstOrFail()->excludes);
    }

    /** 留存是附加價值，失敗不得影響掃描結果回傳。 */
    public function test_scan_still_returns_results_when_persistence_fails(): void
    {
        $this->universe();
        $user = User::factory()->create();

        // 砍掉資料表模擬寫入失敗。
        \Schema::drop('screen_runs');

        $this->actingAs($user)
            ->postJson('/screener/scan', ['rules' => ['above_ma20']])
            ->assertOk()
            ->assertJsonStructure(['results', 'scanned', 'skipped', 'failures']);
    }

    public function test_history_lists_only_own_runs(): void
    {
        $this->universe();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->postJson('/screener/scan', ['rules' => ['above_ma20']]);
        $this->actingAs($other)->postJson('/screener/scan', ['rules' => ['below_ma20']]);

        $runs = $this->actingAs($user)->getJson('/screener/history')->assertOk()->json('runs');

        $this->assertCount(1, $runs);
        $this->assertSame(['above_ma20'], $runs[0]['rules']);
    }

    // --- 加入自選清單 ---

    public function test_matched_symbols_can_be_added_to_a_watchlist(): void
    {
        $this->universe();
        $user = User::factory()->create();
        $watchlist = $user->watchlists()->create(['name' => '核心']);

        $this->actingAs($user)->postJson('/screener/scan', ['rules' => ['above_ma20']]);
        $run = $user->screenRuns()->firstOrFail();

        $this->actingAs($user)->post('/screener/watchlist', [
            'run_id' => $run->id,
            'watchlist_id' => $watchlist->id,
            'symbols' => ['AAA'],
        ])->assertRedirect();

        $this->assertSame(1, $watchlist->items()->count());
        $this->assertSame('AAA', $watchlist->items()->first()->instrument->symbol);
    }

    /** 重複加入直接跳過，不製造重複項目也不覆寫既有備註。 */
    public function test_symbols_already_in_the_watchlist_are_skipped(): void
    {
        $this->universe();
        $user = User::factory()->create();
        $watchlist = $user->watchlists()->create(['name' => '核心']);

        $this->actingAs($user)->postJson('/screener/scan', ['rules' => ['above_ma20']]);
        $run = $user->screenRuns()->firstOrFail();

        $payload = ['run_id' => $run->id, 'watchlist_id' => $watchlist->id, 'symbols' => ['AAA']];
        $this->actingAs($user)->post('/screener/watchlist', $payload);
        $this->actingAs($user)->post('/screener/watchlist', $payload);

        $this->assertSame(1, $watchlist->items()->count());
    }

    /**
     * 只能加入這次掃描實際命中的代號。否則這個端點會變成「繞過個股搜尋直接
     * 把任意 symbol 塞進自選清單」的入口。
     */
    public function test_symbols_outside_the_run_results_are_ignored(): void
    {
        $this->universe();
        // 刻意不把 ZZZ 放進標的清單：它因此不在掃描範圍，也就不會出現在 run
        // results 裡，正好用來驗白名單。（放進去的話它會被掃到並命中，測不到東西。）
        $user = User::factory()->create();
        $watchlist = $user->watchlists()->create(['name' => '核心']);

        $this->actingAs($user)->postJson('/screener/scan', ['rules' => ['above_ma20']]);
        $run = $user->screenRuns()->firstOrFail();

        $this->actingAs($user)->post('/screener/watchlist', [
            'run_id' => $run->id,
            'watchlist_id' => $watchlist->id,
            'symbols' => ['ZZZ'],
        ])->assertRedirect();

        $this->assertSame(0, $watchlist->items()->count());
    }

    public function test_cannot_use_another_users_run_or_watchlist(): void
    {
        $this->universe();
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $this->actingAs($owner)->postJson('/screener/scan', ['rules' => ['above_ma20']]);
        $ownerRun = $owner->screenRuns()->firstOrFail();
        $ownerList = $owner->watchlists()->create(['name' => '核心']);

        $attackerList = $attacker->watchlists()->create(['name' => '我的']);

        // 借用他人的 run
        $this->actingAs($attacker)->post('/screener/watchlist', [
            'run_id' => $ownerRun->id,
            'watchlist_id' => $attackerList->id,
            'symbols' => ['AAA'],
        ])->assertForbidden();

        // 寫入他人的 watchlist
        $this->actingAs($attacker)->postJson('/screener/scan', ['rules' => ['above_ma20']]);
        $attackerRun = $attacker->screenRuns()->firstOrFail();

        $this->actingAs($attacker)->post('/screener/watchlist', [
            'run_id' => $attackerRun->id,
            'watchlist_id' => $ownerList->id,
            'symbols' => ['AAA'],
        ])->assertForbidden();

        $this->assertSame(0, $ownerList->items()->count());
    }

    public function test_guest_cannot_reach_the_new_endpoints(): void
    {
        $this->get('/screener/history')->assertRedirect('/login');
        $this->post('/screener/watchlist', [])->assertRedirect('/login');
    }

    // --- 強度排序 ---

    public function test_results_are_sorted_by_strength_descending(): void
    {
        $this->universe();
        $user = User::factory()->create();

        $results = $this->actingAs($user)
            ->postJson('/screener/scan', ['rules' => ['above_ma20']])
            ->json('results');

        $strengths = array_column($results, 'strength');
        $sorted = $strengths;
        rsort($sorted);

        $this->assertSame($sorted, $strengths);
        $this->assertArrayHasKey('ma20_bias', $results[0]);
        $this->assertArrayHasKey('volume_x', $results[0]);
    }

    public function test_run_results_keep_the_strength_fields(): void
    {
        $this->universe();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/screener/scan', ['rules' => ['above_ma20']]);
        $run = ScreenRun::query()->firstOrFail();

        $this->assertArrayHasKey('strength', $run->results[0]);
    }
}
