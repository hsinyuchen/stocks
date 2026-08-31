<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Http\Middleware\ProcessQueuedAnalyses;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Analysis\InlineQueueWorker;
use App\Services\Llm\LlmProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * queue:doctor 的價值全在「講的是不是 web 端的事實」。
 *
 * 指令自己跑在 CLI，而 CLI 的 max_execution_time 通常是 0（無限）；照著印出來會
 * 讓人以為環境沒問題，但真正會把 job 砍在半路的是 PHP-FPM 那一份設定。
 */
class QueueDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_warns_when_no_web_request_has_been_observed_yet(): void
    {
        Cache::flush();

        $this->artisan('queue:doctor')
            ->expectsOutputToContain('尚未取得 web 端的執行環境')
            ->assertSuccessful();
    }

    public function test_a_web_request_records_the_real_runtime_limits(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $probe = Cache::get(ProcessQueuedAnalyses::RUNTIME_PROBE_KEY);

        $this->assertNotNull($probe);
        $this->assertArrayHasKey('max_execution_time', $probe);
        $this->assertArrayHasKey('set_time_limit_available', $probe);
        $this->assertNotSame('', $probe['sapi']);
    }

    public function test_polling_requests_do_not_record_the_probe(): void
    {
        Cache::flush();

        $user = User::factory()->create();

        // 輪詢整段 middleware 邏輯都跳過，探測自然也不該發生。
        $this->actingAs($user)
            ->withHeaders(['X-Inertia-Partial-Data' => 'analyses'])
            ->get('/dashboard')
            ->assertOk();

        $this->assertNull(Cache::get(ProcessQueuedAnalyses::RUNTIME_PROBE_KEY));
    }

    public function test_it_reports_the_recorded_web_values_instead_of_cli_values(): void
    {
        Cache::flush();
        Cache::put(ProcessQueuedAnalyses::RUNTIME_PROBE_KEY, [
            'sapi' => 'fpm-fcgi',
            'max_execution_time' => 30,
            'set_time_limit_available' => false,
            'observed_at' => now()->toDateTimeString(),
        ], now()->addDay());

        $this->artisan('queue:doctor')
            ->expectsOutputToContain('fpm-fcgi')
            // 30 秒上限又不能放寬，是共享主機上唯一救不了的組合，必須點名。
            ->expectsOutputToContain('set_time_limit 被停用')
            ->assertFailed();
    }

    public function test_it_flags_analyses_stuck_past_the_reaper_threshold(): void
    {
        Cache::flush();
        config(['analysis.pending_timeout_minutes' => 8]);
        Cache::put(ProcessQueuedAnalyses::RUNTIME_PROBE_KEY, [
            'sapi' => 'fpm-fcgi',
            'max_execution_time' => 0,
            'set_time_limit_available' => true,
            'observed_at' => now()->toDateTimeString(),
        ], now()->addDay());

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);
        $analysis = $user->stockAnalyses()->create([
            'instrument_id' => $instrument->id,
            'provider_type' => 'pending',
            'model' => 'gpt-5-mini',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'rule_signal' => [],
            'llm_output' => [],
            'data_as_of' => now(),
        ]);
        $analysis->forceFill(['created_at' => now()->subMinutes(45)])->save();

        $this->artisan('queue:doctor')
            ->expectsOutputToContain('還沒被回收')
            ->assertFailed();
    }

    public function test_required_seconds_follows_the_configured_budgets(): void
    {
        // 受限主機（max_execution_time 只能設到 120）就是靠調低這兩個值把需求壓下來。
        config([
            'analysis.inline_worker.max_seconds' => 20,
            'analysis.llm_timeout_floor' => 60,
        ]);

        $this->assertSame(110, app(InlineQueueWorker::class)->requiredSeconds());

        config([
            'analysis.inline_worker.max_seconds' => 60,
            'analysis.llm_timeout_floor' => 120,
        ]);

        $this->assertSame(210, app(InlineQueueWorker::class)->requiredSeconds());
    }

    public function test_a_tight_host_passes_once_the_budgets_are_lowered(): void
    {
        Cache::flush();
        Cache::put(ProcessQueuedAnalyses::RUNTIME_PROBE_KEY, [
            'sapi' => 'fpm-fcgi',
            'max_execution_time' => 120,
            'set_time_limit_available' => false,
            'observed_at' => now()->toDateTimeString(),
        ], now()->addDay());

        // 預設需求是兩段相加：statements 單筆上限 60 秒＋default 的
        // requiredSeconds()（60+120+30=210）＝270 > 120，必須報警。
        config(['analysis.inline_worker.max_seconds' => 60, 'analysis.llm_timeout_floor' => 120]);
        $this->artisan('queue:doctor')->assertFailed();

        // 壓到 60（statements 上限）+45（default 的 requiredSeconds，5+10+30）=105，
        // 落在 120 的預算之內。
        config(['analysis.inline_worker.max_seconds' => 5, 'analysis.llm_timeout_floor' => 10]);
        $this->artisan('queue:doctor')->assertSuccessful();
    }

    public function test_llm_timeout_floor_is_configurable(): void
    {
        $user = User::factory()->create();
        $setting = $user->llmProviderSettings()->create([
            'provider_type' => 'openai',
            'display_name' => 'GPT',
            'base_url' => null,
            // 使用者填的值低於下限時會被下限蓋過，這正是下限存在的意義。
            'api_key_encrypted' => 'k',
            'model' => 'gpt-5-mini',
            'timeout_seconds' => 30,
            'temperature' => 0.20,
            'max_tokens' => 800,
            'is_default' => true,
            'default_marker' => true,
        ]);

        config(['analysis.llm_timeout_floor' => 60]);
        $provider = app(LlmProviderFactory::class)->make($setting);

        $reflection = new \ReflectionProperty($provider, 'timeoutSeconds');

        $this->assertSame(60, $reflection->getValue($provider));
    }

    /** 兩種取件模式的參數要並列，否則看不出目前實際靠哪一邊在前進。 */
    public function test_it_shows_both_worker_modes(): void
    {
        Cache::flush();
        config([
            'analysis.cron_worker.max_seconds' => 42,
            'analysis.cron_worker.stop_when_empty' => true,
        ]);

        // 不用 expectsOutputToContain：一個期望消耗一次寫入，「cron worker 存活」與
        // 同一行上的「42」不可能同時比對到。
        $this->withoutMockingConsoleOutput();
        $this->artisan('queue:doctor');
        $output = Artisan::output();

        $this->assertStringContainsString('inline worker', $output);
        $this->assertStringContainsString('cron worker 存活', $output);
        $this->assertStringContainsString('42', $output);
        $this->assertStringContainsString('cron worker 空佇列即退出', $output);
    }

    public function test_a_healthy_environment_passes(): void
    {
        Cache::flush();
        Cache::put(ProcessQueuedAnalyses::RUNTIME_PROBE_KEY, [
            'sapi' => 'fpm-fcgi',
            'max_execution_time' => 0,
            'set_time_limit_available' => true,
            'observed_at' => now()->toDateTimeString(),
        ], now()->addDay());

        $this->assertSame(0, DB::table('jobs')->count());

        $this->artisan('queue:doctor')->assertSuccessful();
    }
}
