<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Instruments\InstrumentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI 問答紀錄要算進「被使用者資料參照」。
 *
 * stock_chat_turns 對 instrument 是 cascadeOnDelete。若參照判斷不認得它，一檔
 * 只有聊天紀錄的標的會被管理員刪除、或被「全部取代」匯入清掉，使用者的對話
 * 隨 FK 一起消失，而且畫面上完全不會標示它「被參照」。
 */
class InstrumentChatReferenceTest extends TestCase
{
    use RefreshDatabase;

    private function chatOnlyInstrument(User $user, string $symbol): Instrument
    {
        $instrument = Instrument::factory()->create(['symbol' => $symbol]);

        $user->stockChatTurns()->create([
            'instrument_id' => $instrument->id,
            'provider_type' => 'ollama',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Completed,
            'question' => '這檔最近怎麼樣？',
            'answer' => '參考回答。',
            'metadata' => [],
            'data_as_of' => now(),
        ]);

        return $instrument;
    }

    public function test_instrument_with_only_chat_turns_counts_as_referenced(): void
    {
        $instrument = $this->chatOnlyInstrument(User::factory()->create(), '2337.TW');

        $this->assertTrue($instrument->isReferencedByUserData());
    }

    public function test_admin_cannot_delete_an_instrument_that_only_has_chat_turns(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $instrument = $this->chatOnlyInstrument(User::factory()->create(), '2337.TW');

        $this->actingAs($admin)->delete("/admin/instruments/{$instrument->id}");

        $this->assertDatabaseHas('instruments', ['id' => $instrument->id]);
    }

    /** replace 匯入會刪掉未被參照的標的；只有聊天紀錄的那檔必須留下。 */
    public function test_replace_import_preserves_an_instrument_that_only_has_chat_turns(): void
    {
        $instrument = $this->chatOnlyInstrument(User::factory()->create(), '2337.TW');
        $orphan = Instrument::factory()->create(['symbol' => 'ORPHAN.TW']);

        $result = app(InstrumentImportService::class)->import(
            [['symbol' => '2454.TW', 'name' => '聯發科']],
            'replace',
        );

        $this->assertDatabaseHas('instruments', ['id' => $instrument->id]);
        $this->assertDatabaseMissing('instruments', ['id' => $orphan->id]);
        $this->assertSame(1, $result['protected']);
    }

    public function test_deleting_the_user_removes_their_chat_turns(): void
    {
        $user = User::factory()->create();
        $this->chatOnlyInstrument($user, '2337.TW');

        $user->delete();

        $this->assertDatabaseCount('stock_chat_turns', 0);
    }
}
