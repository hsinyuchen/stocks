<?php

namespace Tests\Feature\Admin;

use App\Models\Instrument;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class InstrumentImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function csv(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'inst').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'instruments.csv', 'text/csv', null, true);
    }

    /** 建立一個被自選清單參照的標的——「全部取代」時必須被保留。 */
    private function watchlisted(User $user, string $symbol): Instrument
    {
        $instrument = Instrument::factory()->create(['symbol' => $symbol, 'name' => $symbol]);
        // user_id 刻意不在 Watchlist 的 $fillable（mass-assignment 護欄），
        // 必須經由關聯建立。
        $watchlist = $user->watchlists()->create(['name' => 'W']);
        $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);

        return $instrument;
    }

    // --- 權限 ---

    public function test_non_admin_cannot_reach_the_instrument_admin(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/instruments')->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->post('/admin/instruments/import', ['mode' => 'append'])
            ->assertForbidden();
    }

    public function test_admin_can_view_the_instrument_list(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '台積電']);

        $this->actingAs($this->admin())->get('/admin/instruments')->assertOk();
    }

    // --- CSV 匯入 ---

    public function test_append_mode_creates_new_instruments(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/instruments/import', [
                'file' => $this->csv("symbol,name\n2330.TW,台積電\nNVDA,NVIDIA\n"),
                'mode' => 'append',
            ])
            ->assertRedirect();

        $this->assertSame(2, Instrument::query()->count());
        $this->assertSame('台積電', Instrument::query()->where('symbol', '2330.TW')->value('name'));
    }

    /** 市場、幣別、資產類型由代號推導，不需檔案提供。 */
    public function test_market_and_currency_are_derived_from_the_symbol(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/instruments/import', ['file' => $this->csv("2330.TW,台積電\nNVDA,NVIDIA\n"), 'mode' => 'append']);

        $this->assertSame('TWD', Instrument::query()->where('symbol', '2330.TW')->value('currency'));
        $this->assertSame('USD', Instrument::query()->where('symbol', 'NVDA')->value('currency'));
    }

    /** 重複代號直接跳過，不覆寫既有名稱。 */
    public function test_duplicates_are_skipped_without_overwriting(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '既有名稱']);

        $this->actingAs($this->admin())
            ->post('/admin/instruments/import', ['file' => $this->csv("2330.TW,新名稱\nNVDA,NVIDIA\n"), 'mode' => 'append']);

        $this->assertSame(2, Instrument::query()->count());
        $this->assertSame('既有名稱', Instrument::query()->where('symbol', '2330.TW')->value('name'));
    }

    /** 檔案內部的重複也只取第一筆。 */
    public function test_duplicates_within_the_file_are_collapsed(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/instruments/import', ['file' => $this->csv("NVDA,NVIDIA\nnvda,重複\n"), 'mode' => 'append']);

        $this->assertSame(1, Instrument::query()->count());
    }

    public function test_header_row_is_detected_and_skipped(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/instruments/import', ['file' => $this->csv("代號,名稱\n2330.TW,台積電\n"), 'mode' => 'append']);

        $this->assertSame(1, Instrument::query()->count());
        $this->assertNull(Instrument::query()->where('symbol', '代號')->first());
    }

    /** Excel 匯出的 CSV 幾乎都帶 BOM，不處理會讓標題比對失敗。 */
    public function test_utf8_bom_is_stripped(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/instruments/import', [
                'file' => $this->csv("\xEF\xBB\xBFsymbol,name\n2330.TW,台積電\n"),
                'mode' => 'append',
            ]);

        $this->assertSame(1, Instrument::query()->count());
        $this->assertSame('2330.TW', Instrument::query()->value('symbol'));
    }

    /** 代號會被拿去打上游 API，格式無效者不得寫入。 */
    public function test_invalid_symbols_are_rejected_not_imported(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/instruments/import', [
                'file' => $this->csv("NVDA,NVIDIA\n'; DROP TABLE,惡意\n上市股票,中文代號\n"),
                'mode' => 'append',
            ]);

        $this->assertSame(1, Instrument::query()->count());
        $this->assertSame('NVDA', Instrument::query()->value('symbol'));
    }

    // --- 全部取代：使用者資料保護 ---

    /**
     * 這是本功能最關鍵的行為。instruments 被 8 個表以 cascade 參照，
     * 天真的「全部取代」會連同使用者的自選股一起刪掉。
     */
    public function test_replace_keeps_instruments_referenced_by_a_watchlist(): void
    {
        $admin = $this->admin();
        $kept = $this->watchlisted($admin, 'KEEP.TW');
        Instrument::factory()->create(['symbol' => 'ORPHAN.TW', 'name' => '無人參照']);

        $this->actingAs($admin)
            ->post('/admin/instruments/import', ['file' => $this->csv("NVDA,NVIDIA\n"), 'mode' => 'replace']);

        $this->assertDatabaseHas('instruments', ['id' => $kept->id]);
        $this->assertSame(1, $kept->watchlistItems()->count(), '自選項目不得被連帶刪除。');
        $this->assertDatabaseMissing('instruments', ['symbol' => 'ORPHAN.TW']);
        $this->assertDatabaseHas('instruments', ['symbol' => 'NVDA']);
    }

    public function test_replace_keeps_instruments_referenced_by_saved_analyses(): void
    {
        $admin = $this->admin();
        $instrument = Instrument::factory()->create(['symbol' => 'ANALYSED.TW']);
        $admin->stockAnalyses()->create([
            'instrument_id' => $instrument->id,
            'technical_snapshot_id' => null,
            'provider_type' => 'none',
            'model' => 'm',
            'prompt_version' => 'v1',
            'rule_signal' => [],
            'llm_output' => [],
            'data_as_of' => now(),
        ]);

        $this->actingAs($admin)
            ->post('/admin/instruments/import', ['file' => $this->csv("NVDA,NVIDIA\n"), 'mode' => 'replace']);

        $this->assertDatabaseHas('instruments', ['id' => $instrument->id]);
        $this->assertSame(1, $admin->stockAnalyses()->count());
    }

    /** 取代時檔案內的代號也要保留，不能先刪再建而丟失既有名稱。 */
    public function test_replace_keeps_symbols_present_in_the_file(): void
    {
        Instrument::factory()->create(['symbol' => 'NVDA', 'name' => 'NVIDIA Corporation']);

        $this->actingAs($this->admin())
            ->post('/admin/instruments/import', ['file' => $this->csv("NVDA,NVIDIA\n"), 'mode' => 'replace']);

        $this->assertSame('NVIDIA Corporation', Instrument::query()->where('symbol', 'NVDA')->value('name'));
    }

    // --- 單筆維護 ---

    public function test_admin_can_rename_an_instrument(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '2330.TW']);

        $this->actingAs($this->admin())
            ->patch("/admin/instruments/{$instrument->id}", ['name' => '台積電'])
            ->assertRedirect();

        $this->assertSame('台積電', $instrument->fresh()->name);
    }

    public function test_referenced_instrument_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $instrument = $this->watchlisted($admin, 'KEEP.TW');

        $this->actingAs($admin)
            ->delete("/admin/instruments/{$instrument->id}")
            ->assertSessionHasErrors('instrument');

        $this->assertDatabaseHas('instruments', ['id' => $instrument->id]);
    }

    public function test_unreferenced_instrument_can_be_deleted(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => 'ORPHAN.TW']);

        $this->actingAs($this->admin())->delete("/admin/instruments/{$instrument->id}")->assertRedirect();

        $this->assertDatabaseMissing('instruments', ['id' => $instrument->id]);
    }

    public function test_adding_a_duplicate_symbol_is_rejected(): void
    {
        Instrument::factory()->create(['symbol' => 'NVDA']);

        $this->actingAs($this->admin())
            ->post('/admin/instruments', ['symbol' => 'NVDA', 'name' => 'x'])
            ->assertSessionHasErrors('symbol');

        $this->assertSame(1, Instrument::query()->count());
    }
}
