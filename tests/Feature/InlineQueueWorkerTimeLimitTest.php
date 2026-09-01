<?php

namespace Tests\Feature;

use App\Jobs\FetchFinancialStatements;
use App\Services\Analysis\InlineQueueWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I-1：ProcessQueuedAnalyses 對 statements、default 兩個佇列各呼叫一次
 * InlineQueueWorker::drain()，兩段各自要有完整的執行時間預算，不能共用同一份
 * set_time_limit() 倒數視窗。
 *
 * PHP 沒有辦法事後觀察「倒數是否被重啟過」——ini_get('max_execution_time')
 * 讀到的只是設定值，呼叫前後可能是同一個數字，即使倒數確實被重啟了。因此
 * 「該重啟時真的會呼叫 set_time_limit()」這件事，只能靠反射呼叫
 * InlineQueueWorker::nextTimeLimit()（relaxTimeLimit() 抽出來的純函式）
 * 直接斷言回傳值來驗證，見 test_the_decision_restarts_the_countdown_on_every_call()。
 */
class InlineQueueWorkerTimeLimitTest extends TestCase
{
    use RefreshDatabase;

    private function nextTimeLimit(int $current, int $required): ?int
    {
        $method = new \ReflectionMethod(InlineQueueWorker::class, 'nextTimeLimit');
        $method->setAccessible(true);

        return $method->invoke(app(InlineQueueWorker::class), $current, $required);
    }

    /**
     * 核心迴歸案例：模擬 statements 段 relaxTimeLimit() 已經把 ini 值放寬到
     * 210（第一次呼叫，60 < 210），default 段接著呼叫時 current 已經等於
     * required。舊邏輯（`$current < $required ? $required : null`）在這裡會
     * 回傳 null——不再呼叫 set_time_limit()，倒數視窗停留在 statements 段
     * 開始時就設定好的那一份，default 段完全沒有拿到自己的預算。新邏輯必須
     * 仍然回傳非 null（即使數值不變），因為呼叫 set_time_limit() 本身才是
     * 「重啟倒數」的動作。
     *
     * 變異驗證：把 nextTimeLimit() 改回舊條件式，這裡的第二個斷言會變紅。
     */
    public function test_the_decision_restarts_the_countdown_on_every_call(): void
    {
        // 第一段：60 秒的主機上限，需求是 210 秒 → 必須放寬。
        $this->assertSame(210, $this->nextTimeLimit(60, 210));

        // 第二段：ini 已經是 210（第一段剛設定的），需求仍是 210——
        // 舊邏輯在這裡會判定「不需要再放寬」而回傳 null，導致 set_time_limit()
        // 不會被呼叫、倒數視窗不會重啟。新邏輯必須仍然回傳 210（而不是 null），
        // 讓 relaxTimeLimit() 呼叫 set_time_limit(210)，重新從呼叫當下起算。
        $this->assertSame(210, $this->nextTimeLimit(210, 210));
    }

    public function test_unlimited_current_is_left_untouched(): void
    {
        // max_execution_time = 0 是「無限制」，不能被無條件重啟蓋成有限制。
        $this->assertNull($this->nextTimeLimit(0, 210));
    }

    public function test_never_narrows_a_larger_existing_limit(): void
    {
        // 主機本來就給了比 required 更大的上限時，重啟只能維持原值，不能縮短。
        $this->assertSame(500, $this->nextTimeLimit(500, 210));
    }

    /**
     * 端到端接線：確認 drain() 真的會呼叫到 relaxTimeLimit()／nextTimeLimit()，
     * 而不是只有前面那條單元測試在測一個沒有被用到的方法。用 ini_set 模擬
     * 受限主機的起始值，跑完兩段 drain 後斷言 ini_get() 的最終值涵蓋得住
     * InlineQueueWorker::worstCaseSeconds() 算出的最壞情況。
     *
     * 這條測不出「倒數有沒有重啟」（原因見類別 docblock），但測得出「wiring
     * 沒有斷掉」：nextTimeLimit() 對就沒用，若 relaxTimeLimit() 忘記呼叫它，
     * 或 drain() 忘記呼叫 relaxTimeLimit()，這裡會抓到。
     */
    public function test_two_drain_calls_leave_the_ini_value_covering_the_worst_case(): void
    {
        // ini_set() 成功時回傳「舊值」，舊值恰好是 "0"（無限制，CLI 的常見預設）
        // 時字串 "0" 在布林情境下是 false——不能用回傳值的真假判斷有沒有設定
        // 成功，必須讀回 ini_get() 確認。
        if (! function_exists('set_time_limit')) {
            $this->markTestSkipped('這個環境不支援 set_time_limit()，測不出這條規則。');
        }

        ini_set('max_execution_time', '60');

        if ((int) ini_get('max_execution_time') !== 60) {
            $this->markTestSkipped('這個環境不允許調整 max_execution_time，測不出這條規則。');
        }

        config([
            'queue.default' => 'database',
            'analysis.inline_worker.max_seconds' => 60,
            'analysis.llm_timeout_floor' => 120,
        ]);

        FetchFinancialStatements::dispatch(999999, 1); // instrumentId 不存在，handle() 直接 return，快速結束。

        $worker = app(InlineQueueWorker::class);
        $worker->drain('statements');
        $worker->drain();

        $this->assertGreaterThanOrEqual(
            $worker->requiredSeconds(),
            (int) ini_get('max_execution_time'),
            '兩段 drain 之後，PHP 的執行時間上限必須至少涵蓋單段的最壞情況'
        );

        ini_set('max_execution_time', '0');
    }
}
