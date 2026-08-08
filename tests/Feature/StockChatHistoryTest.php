<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Models\Instrument;
use App\Models\StockChatTurn;
use App\Models\User;
use App\Services\Analysis\StockChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 送進 prompt 的對話歷史。
 *
 * 挑錯輪次會直接讓「那風險呢」這類追問指向錯的前文，而使用者從答案裡看不出來
 * 是歷史選錯了，只會覺得模型很笨。
 */
class StockChatHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function turn(
        User $user,
        Instrument $instrument,
        string $question,
        ?string $answer = '回答',
        AnalysisStatus $status = AnalysisStatus::Completed,
    ): StockChatTurn {
        return $user->stockChatTurns()->create([
            'instrument_id' => $instrument->id,
            'provider_type' => 'ollama',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'status' => $status,
            'question' => $question,
            'answer' => $answer,
            'metadata' => [],
            'data_as_of' => now(),
        ]);
    }

    public function test_history_is_truncated_to_the_configured_number_of_turns(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        for ($i = 1; $i <= 10; $i++) {
            $this->turn($user, $instrument, "第 {$i} 題");
        }

        $latest = $this->turn($user, $instrument, '最新題');
        $history = StockChatTurn::historyBefore($latest, StockChatService::HISTORY_TURNS);

        $this->assertCount(6, $history);
        $this->assertSame('第 5 題', $history[0]['question']);
        $this->assertSame('第 10 題', $history[5]['question']);
    }

    /** failed 的 answer 是錯誤訊息，pending 沒有 answer，兩者都會污染下一輪。 */
    public function test_history_excludes_pending_and_failed_turns(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $this->turn($user, $instrument, '成功題');
        $this->turn($user, $instrument, '失敗題', 'AI 服務逾時。', AnalysisStatus::Failed);
        $this->turn($user, $instrument, '排隊題', null, AnalysisStatus::Pending);

        $latest = $this->turn($user, $instrument, '最新題');
        $history = StockChatTurn::historyBefore($latest, StockChatService::HISTORY_TURNS);

        $this->assertCount(1, $history);
        $this->assertSame('成功題', $history[0]['question']);
    }

    public function test_history_excludes_other_instruments_and_other_users(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $a = Instrument::factory()->create(['symbol' => '2337.TW']);
        $b = Instrument::factory()->create(['symbol' => '2330.TW']);

        $this->turn($user, $a, '同檔同人');
        $this->turn($user, $b, '他檔同人');
        $this->turn($other, $a, '同檔他人');

        $latest = $this->turn($user, $a, '最新題');
        $history = StockChatTurn::historyBefore($latest, StockChatService::HISTORY_TURNS);

        $this->assertCount(1, $history);
        $this->assertSame('同檔同人', $history[0]['question']);
    }

    /**
     * 同一秒建立的回合也要有決定性的順序。
     *
     * 用 id 而非 created_at 排序的理由：MySQL 的 timestamp 預設沒有微秒精度，
     * 連續送出的回合 created_at 會完全相同，時間排序等於隨機。
     */
    public function test_history_order_is_deterministic_for_same_second_turns(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);
        $now = now();

        foreach (['第一', '第二', '第三'] as $question) {
            $this->turn($user, $instrument, $question)
                ->forceFill(['created_at' => $now, 'updated_at' => $now])
                ->save();
        }

        $latest = $this->turn($user, $instrument, '最新題');
        $latest->forceFill(['created_at' => $now])->save();

        $history = StockChatTurn::historyBefore($latest, StockChatService::HISTORY_TURNS);

        $this->assertSame(['第一', '第二', '第三'], array_column($history, 'question'));
    }

    public function test_history_is_a_json_array_not_an_object(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);
        $this->turn($user, $instrument, '第一');
        $this->turn($user, $instrument, '第二');

        $latest = $this->turn($user, $instrument, '最新題');
        $history = StockChatTurn::historyBefore($latest, StockChatService::HISTORY_TURNS);

        // reverse() 會保留原 key；沒有 values() 的話 json_encode 會產出物件而非陣列。
        $this->assertSame([0, 1], array_keys($history));
        $this->assertStringStartsWith('[', (string) json_encode($history));
    }
}
