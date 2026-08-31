<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\LlmFailureReason;
use App\Jobs\RunStockAnalysis;
use App\Models\Instrument;
use App\Models\NewsItem;
use App\Models\StockAnalysis;
use App\Models\StockChatTurn;
use App\Models\User;
use App\Services\Analysis\StaleAnalysisReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 沒有常駐 worker、也沒有排程的部署環境。
 *
 * 這是實際遇到的情況：雲端主機上只跑得起 web server，佇列因此沒有人取件，分析
 * 永遠停在「分析中」。兩道機制要能各自成立——回應後就地取件，以及超時回收。
 */
class QueuelessEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Eloquent 會在 create() 時自行寫入 timestamps，陣列裡帶 created_at 沒有用；
     * 要模擬「很久以前建立的紀錄」只能寫完再改。
     */
    private function backdate(object $model, int $minutes): void
    {
        $model->forceFill(['created_at' => now()->subMinutes($minutes)])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingAttributes(Instrument $instrument): array
    {
        return [
            'instrument_id' => $instrument->id,
            'provider_type' => 'pending',
            'model' => 'gpt-5-mini',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'rule_signal' => [],
            'llm_output' => [],
            'data_as_of' => now(),
        ];
    }

    public function test_reaper_fails_analyses_that_waited_past_the_timeout(): void
    {
        config(['analysis.pending_timeout_minutes' => 8]);

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);

        $stale = $user->stockAnalyses()->create($this->pendingAttributes($instrument));
        $this->backdate($stale, 9);
        $fresh = $user->stockAnalyses()->create($this->pendingAttributes($instrument));

        $result = app(StaleAnalysisReaper::class)->reap();

        $this->assertSame(1, $result['stock']);
        $this->assertSame(AnalysisStatus::Failed, $stale->fresh()->status);
        // 還在時限內的不能被掃到，否則正常的分析會被誤殺。
        $this->assertSame(AnalysisStatus::Pending, $fresh->fresh()->status);
    }

    public function test_reaped_analysis_explains_why_it_ended(): void
    {
        config(['analysis.pending_timeout_minutes' => 8]);

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);
        $stale = $user->stockAnalyses()->create($this->pendingAttributes($instrument));
        $this->backdate($stale, 30);

        app(StaleAnalysisReaper::class)->reap();

        $output = $stale->fresh()->llm_output;
        $this->assertSame(LlmFailureReason::Timeout->value, $output['metadata']['failure']['reason']);
        $this->assertTrue($output['metadata']['reaped']);
        $this->assertStringContainsString('已自動結束', $output['content']);
    }

    public function test_reaper_discards_expired_jobs_but_keeps_reserved_ones(): void
    {
        // 只有 database driver 會把工作留在表裡，sync 沒有可清的東西。
        config(['analysis.pending_timeout_minutes' => 8, 'queue.default' => 'database']);

        $old = now()->subMinutes(30)->getTimestamp();

        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $old, 'created_at' => $old],
            // 已被某個 worker 取走並正在執行，不能從它腳下抽掉。
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => $old, 'available_at' => $old, 'created_at' => $old],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()],
        ]);

        $result = app(StaleAnalysisReaper::class)->reap();

        $this->assertSame(1, $result['jobs']);
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('jobs')->whereNotNull('reserved_at')->count());
    }

    public function test_news_analyses_are_reaped_too(): void
    {
        config(['analysis.pending_timeout_minutes' => 8]);

        $user = User::factory()->create();
        $item = NewsItem::create([
            'source' => 'CNBC', 'title' => 't', 'summary' => 's',
            'url' => 'https://news.test/a', 'url_hash' => sha1('a'),
            'published_at' => now(), 'language' => 'en', 'market' => 'US',
            'topic' => 'macro', 'domain' => 'tech', 'related_symbols' => [],
        ]);

        $analysis = $user->newsAnalyses()->create([
            'news_item_id' => $item->id,
            'type' => 'item',
            'provider_type' => 'pending',
            'model' => 'gpt-5-mini',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'related_symbols' => [],
            'raw_output' => [],
            'data_as_of' => now(),
        ]);
        $this->backdate($analysis, 20);

        $result = app(StaleAnalysisReaper::class)->reap();

        $this->assertSame(1, $result['news']);
        $this->assertSame(AnalysisStatus::Failed, $analysis->fresh()->status);
    }

    /** 沒有這段，問答會永遠停在「思考中」，而且同檔的「1 題上限」會讓它再也無法提問。 */
    public function test_stale_chat_turns_are_reaped_too(): void
    {
        config(['analysis.pending_timeout_minutes' => 8]);

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $stale = $user->stockChatTurns()->create([
            'instrument_id' => $instrument->id,
            'provider_type' => 'pending',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'question' => '技術面如何？',
            'metadata' => [],
            'data_as_of' => now(),
        ]);
        $this->backdate($stale, 9);

        $result = app(StaleAnalysisReaper::class)->reap();

        $this->assertSame(1, $result['chat']);

        $fresh = $stale->fresh();
        $this->assertSame(AnalysisStatus::Failed, $fresh->status);
        $this->assertSame('error', $fresh->provider_type);
        $this->assertTrue($fresh->metadata['reaped']);
        $this->assertSame(LlmFailureReason::Timeout->value, $fresh->metadata['failure']['reason']);
        // answer 不能留空：畫面上會變成一張沒有任何說明的卡片。
        $this->assertStringContainsString('已自動結束', (string) $fresh->answer);
    }

    /**
     * reaper 不得覆寫掉已經完成的紀錄。
     *
     * 競態是真的：reaper 先 get() 拿到 pending 快照，worker 在那之後把同一筆寫成
     * 完成，若 update 不帶 status 條件，一次已經付費的回答就會被抹成失敗。
     */
    public function test_reaper_does_not_overwrite_a_turn_that_completed_in_the_meantime(): void
    {
        config(['analysis.pending_timeout_minutes' => 8]);

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2337.TW']);

        $turn = $user->stockChatTurns()->create([
            'instrument_id' => $instrument->id,
            'provider_type' => 'pending',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'question' => '技術面如何？',
            'metadata' => [],
            'data_as_of' => now(),
        ]);
        $this->backdate($turn, 9);

        // 模擬 worker 在 reaper 讀取之後、寫入之前完成這一筆。
        StockChatTurn::query()->whereKey($turn->id)->update([
            'status' => AnalysisStatus::Completed->value,
            'provider_type' => 'ollama',
            'answer' => '已完成的回答。',
        ]);

        $result = app(StaleAnalysisReaper::class)->reap();

        $fresh = $turn->fresh();
        $this->assertSame(AnalysisStatus::Completed, $fresh->status);
        $this->assertSame('已完成的回答。', $fresh->answer);
        // 計數要反映真正被回收的筆數，不是候選筆數。
        $this->assertSame(0, $result['chat']);
    }

    public function test_reaper_is_throttled_so_every_request_does_not_rescan(): void
    {
        Cache::flush();
        config(['analysis.reaper_throttle_seconds' => 60]);

        $this->assertNotNull(app(StaleAnalysisReaper::class)->reapThrottled());
        // 併發或連續 request 只有第一個該真的掃描。
        $this->assertNull(app(StaleAnalysisReaper::class)->reapThrottled());
    }

    public function test_middleware_drains_the_queue_after_the_response_is_sent(): void
    {
        $user = User::factory()->create();
        $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Local Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);

        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response([
                'model' => 'llama3.1',
                'choices' => [['message' => ['content' => '分析內容']]],
            ], 200),
        ]);

        // database driver 才會把 job 留在表裡等人取件；sync 會在 dispatch 當下
        // 就地跑完，那樣測到的不是這裡要驗的機制。
        config(['queue.default' => 'database']);

        $this->actingAs($user)->post("/stocks/{$instrument->id}/analyses")->assertRedirect();

        // 回應送出後的 terminate 階段就取件執行完畢——這正是無 worker 環境的解法。
        $analysis = StockAnalysis::query()->sole();
        $this->assertSame(AnalysisStatus::Completed, $analysis->status);
        $this->assertSame('分析內容', $analysis->llm_output['content']);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_later_page_view_picks_up_work_left_behind(): void
    {
        // 前一個 request 可能在 defer 途中被 PHP 的執行時間上限砍掉，job 因此留在
        // 佇列。任何後續的一般頁面瀏覽都要能接手，否則積壓永遠不會被消化。
        $user = User::factory()->create();
        $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Local Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);
        $analysis = $user->stockAnalyses()->create($this->pendingAttributes($instrument));

        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response([
                'model' => 'llama3.1',
                'choices' => [['message' => ['content' => '補跑的分析']]],
            ], 200),
        ]);

        config(['queue.default' => 'database']);
        Queue::connection('database')->push(
            new RunStockAnalysis($analysis->id, $user->defaultLlmSetting()->id, 'llama3.1'),
        );
        $this->assertSame(1, DB::table('jobs')->count());

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame(AnalysisStatus::Completed, $analysis->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * 迴歸測試：ProcessQueuedAnalyses 曾經用字面量 'default' 呼叫
     * pendingCount()／drain()，與 StaleAnalysisReaper::discardStaleJobs() 的
     * 動態算式 config('queue.connections.database.queue', 'default') 認知不一致。
     * DB_QUEUE 被改成非 'default' 的值時（例如共享 DB 上做佇列命名空間隔離），
     * reaper 仍抓得到正確佇列，但舊版 middleware 會排錯佇列名，inline 模式下
     * 分析 job 永遠取不到件。這裡把佇列改名成 'analysis-ns'，驗證改動後
     * 一般頁面瀏覽仍然消化得到它。
     */
    public function test_inline_drain_follows_a_renamed_default_queue(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.queue' => 'analysis-ns',
        ]);

        $user = User::factory()->create();
        $user->llmProviderSettings()->create([
            'provider_type' => 'ollama',
            'display_name' => 'Local Ollama',
            'base_url' => null,
            'api_key_encrypted' => null,
            'model' => 'llama3.1',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);
        $analysis = $user->stockAnalyses()->create($this->pendingAttributes($instrument));

        Http::fake([
            'http://localhost:11434/v1/chat/completions' => Http::response([
                'model' => 'llama3.1',
                'choices' => [['message' => ['content' => '補跑的分析']]],
            ], 200),
        ]);

        // 不呼叫 onQueue()，走連線設定的預設佇列——此時就是改名後的 'analysis-ns'。
        Queue::connection('database')->push(
            new RunStockAnalysis($analysis->id, $user->defaultLlmSetting()->id, 'llama3.1'),
        );
        $this->assertSame(1, DB::table('jobs')->where('queue', 'analysis-ns')->count());

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame(AnalysisStatus::Completed, $analysis->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_polling_requests_do_not_drain_the_queue(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);
        $user->stockAnalyses()->create($this->pendingAttributes($instrument));

        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp(),
        ]);

        // 輪詢每 3 秒一次；讓它也去搶 job 只會讓同一批工作被反覆嘗試。middleware
        // 的判斷條件就是這個標頭（不帶 X-Inertia 是為了避開版本比對的 409，這裡
        // 要驗的是 skip 判斷本身）。
        $this->actingAs($user)
            ->withHeaders(['X-Inertia-Partial-Data' => 'analyses'])
            ->get('/stocks/search?symbol=NVDA')
            ->assertOk();

        $this->assertSame(1, DB::table('jobs')->count());
    }
}
