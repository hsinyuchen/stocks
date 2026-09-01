<?php

namespace App\Console\Commands;

use App\Contracts\FinancialStatementSource;
use App\Jobs\FetchFinancialStatements;
use App\Models\FinancialStatement;
use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use App\Models\WatchlistItem;
use App\Services\FinancialStatements\FinancialStatementDispatcher;
use App\Services\FinancialStatements\FinancialStatementsReader;
use App\Services\FinancialStatements\FinancialStatementWriter;
use App\Services\FinancialStatements\TaiwanAnnualDeriver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 上線前預熱：涵蓋全部使用者自選清單出現過的 instrument，逐檔補齊財報三表。
 *
 * **刻意不派進 `statements` 佇列，同步、逐檔、自帶節流**：那個佇列的量有界
 * （使用者逐檔瀏覽觸發）是它能對 `default` 保持嚴格優先序的前提。預熱一次
 * 涵蓋數十到數百檔，若灌進佇列會讓這個優先序反過來餓死 `default`，分析功能
 * 整個停擺。所以本指令直接在前景解析 {@see FetchFinancialStatements::handle()}
 * 的依賴並呼叫它，讓整輪預熱的步調完全由 `--sleep` 掌控，不受佇列 worker
 * 數量影響。
 *
 * 派工前必須先走一遍 {@see FinancialStatementDispatcher}
 * 的 claim 邏輯（INSERT IGNORE ＋ 條件 UPDATE 取得 generation）：狀態列不會
 * 自己出現，`FetchFinancialStatements::handle()` 開頭的 `markRunning()` 是
 * `queued → running` 的 CAS，沒有先 claim 出一列 `queued`，CAS 必定落空、
 * 整檔靜默跳過。Dispatcher 的 `claim()`／`insertIgnore()` 是 `private`，且
 * Global Constraints 禁止修改 Task 7 的檔案，所以這裡是刻意的邏輯複製而非
 * 呼叫共用方法——複製範圍限定在建立/認領狀態列這一步，其餘（新鮮度判斷、
 * 節流、例外隔離）是本指令獨有的規則。
 */
class WarmFinancialStatements extends Command
{
    protected $signature = 'financials:warm {--limit=50 : 本次最多處理幾檔} {--sleep=1 : 每次實際擷取後的秒數}';

    protected $description = '預熱自選清單涵蓋的 instrument 財報三表（同步、逐檔、自帶節流，不進佇列）。';

    public function handle(
        FinancialStatementSource $source,
        FinancialStatementWriter $writer,
        TaiwanAnnualDeriver $deriver,
    ): int {
        // 沒有上限的話，額度用盡後同一輪會繼續對後面幾百檔空跑（每檔都是
        // claim → 上游拒絕 → unsupported/failed），白白拉長執行時間。
        $limit = max(0, (int) $this->option('limit'));
        // 0 是合法值（測試用，避免每個案例真的等待整秒）；用 max(0, …) 而不是
        // max(1, …)，否則 --sleep=0 會被悄悄拉回 1 秒，測試套件會被拖慢。
        $sleepSeconds = max(0, (int) $this->option('sleep'));

        $instrumentIds = $this->candidateInstrumentIds($limit);

        if ($instrumentIds === []) {
            $this->info('自選清單目前沒有任何標的，無需預熱。');

            return self::SUCCESS;
        }

        $succeeded = 0;
        $unsupported = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($instrumentIds as $instrumentId) {
            $instrument = Instrument::find($instrumentId);

            if ($instrument === null) {
                // 候選清單是快照；理論上不會發生（cascade 刪除也會連帶清掉
                // watchlist_items），防禦性處理避免整輪因為一筆髒資料中止。
                $skipped++;

                continue;
            }

            $outcome = $this->warmOne($instrument, $source, $writer, $deriver, $sleepSeconds);

            match ($outcome) {
                'succeeded' => $succeeded++,
                'unsupported' => $unsupported++,
                'failed' => $failed++,
                default => $skipped++,
            };
        }

        // 統計列刻意用「略過」而不是「跳過」：per-item 的跳過訊息（見
        // warmOne()）用字是「跳過」，若統計列也用同一個詞，測試只斷言輸出
        // 含有「跳過」字樣時，不論有沒有任何一檔真的被跳過都會通過——統計列
        // 每次都會印出「跳過 0」這種字面組合。兩種訊息故意用不同字，讓
        // 「輸出含跳過」這件事只可能來自真的發生過的 per-item 跳過。
        $this->info(sprintf(
            '預熱完成：成功 %d、不支援 %d、失敗 %d、略過 %d（共 %d 檔）。',
            $succeeded,
            $unsupported,
            $failed,
            $skipped,
            count($instrumentIds),
        ));

        return self::SUCCESS;
    }

    /**
     * 處理單一標的，任何例外都在這裡截住——一檔壞掉（例如上游丟出未預期的
     * 例外、寫入層炸掉）不該讓整輪預熱中止，後面幾十檔還等著被預熱。
     *
     * @return string 'succeeded' | 'unsupported' | 'failed' | 'skipped'
     */
    private function warmOne(
        Instrument $instrument,
        FinancialStatementSource $source,
        FinancialStatementWriter $writer,
        TaiwanAnnualDeriver $deriver,
        int $sleepSeconds,
    ): string {
        try {
            $existing = DB::table('financial_statement_fetches')
                ->where('instrument_id', $instrument->id)
                ->first();

            $skipReason = $this->skipReason($existing, $instrument);

            if ($skipReason !== null) {
                $this->line("  {$instrument->symbol} 跳過（{$skipReason}）");

                return 'skipped';
            }

            $generation = $this->claim($instrument);

            if ($generation === null) {
                // claim 沒中：多半是併發下狀態剛好被別的流程動過（例如同一時間
                // 有使用者瀏覽觸發了 Dispatcher）。不是錯誤，一樣算跳過。
                $this->line("  {$instrument->symbol} 跳過（無法認領，狀態列已被其他流程接手）");

                return 'skipped';
            }

            (new FetchFinancialStatements($instrument->id, $generation))
                ->handle($source, $writer, $deriver);

            // 節流只保護真的打出去的上游請求；跳過與認領失敗都沒有 HTTP
            // 呼叫，不需要為它們等待。
            $this->throttle($sleepSeconds);

            $status = (string) DB::table('financial_statement_fetches')
                ->where('instrument_id', $instrument->id)
                ->value('status');

            return match ($status) {
                'succeeded' => $this->reportSucceeded($instrument),
                'unsupported' => $this->reportUnsupported($instrument),
                default => $this->reportFailed($instrument, "終態為 {$status}"),
            };
        } catch (Throwable $exception) {
            $this->closeOutAfterException($instrument, $generation ?? null);

            return $this->reportFailed($instrument, $exception->getMessage());
        }
    }

    /**
     * 同步呼叫 handle() 略過了 Laravel 佇列在耗盡重試後才會呼叫的
     * {@see FetchFinancialStatements::failed()}。少了這一步，例外會讓狀態列
     * 卡在 running 直到 StaleFetchReaper 的 stale_after_seconds（預設 240 秒）
     * 過去才被收割——這段期間任何使用者瀏覽這檔標的都會被 Dispatcher 的
     * claim() 擋下（running 不是終態），畫面停在「更新中」卻沒有任何 worker
     * 在跑。這裡自己補上等效的終態關閉，讓下一次瀏覽或下一輪預熱能立刻重試。
     *
     * $generation 為 null 代表例外發生在 claim() 成功之前，狀態列還沒被
     * claim（或根本沒被本次執行動到），沒有終態可關；markTerminal() 本身
     * 也只在 status = running 時才會生效，claim() 成功但 handle() 尚未把它
     * 轉成 running 前就拋例外時，這裡是安全的 no-op。
     */
    private function closeOutAfterException(Instrument $instrument, ?int $generation): void
    {
        if ($generation === null) {
            return;
        }

        FinancialStatementFetch::where('instrument_id', $instrument->id)
            ->first()
            ?->markTerminal(
                $generation,
                'failed',
                'exception',
                now()->addMinutes((int) config('financial_statements.retry_after.failed_minutes')),
            );
    }

    /**
     * 實際的節流動作，抽成獨立方法純粹是為了可讀性（呼叫端不必重複寫
     * `if ($seconds > 0)`）。測試用 --sleep=0 讓大部分案例不需要真的等待；
     * 唯一驗證「正數真的會延遲」的測試改用 1 秒＋量測耗時，換取不必為了
     * 一個節流開關而在生產程式碼裡開一道測試專用的覆寫縫。
     */
    protected function throttle(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    private function reportSucceeded(Instrument $instrument): string
    {
        $this->info("  {$instrument->symbol} 成功");

        return 'succeeded';
    }

    private function reportUnsupported(Instrument $instrument): string
    {
        $this->line("  {$instrument->symbol} 不支援");

        return 'unsupported';
    }

    private function reportFailed(Instrument $instrument, string $reason): string
    {
        $this->warn("  {$instrument->symbol} 失敗：{$reason}");

        return 'failed';
    }

    /**
     * 全部使用者自選清單出現過的 instrument，去重後依 id 升冪排序再取前 N 筆。
     *
     * 排序刻意明確給定：不下 orderBy 時多筆使用者的自選清單在什麼順序被撈出來
     * 沒有保證，`--limit` 砍掉的會是「不確定的那幾檔」，同一份資料兩次執行可能
     * 覆蓋到不同標的，讓「這檔到底有沒有被預熱過」變得無法追問。
     *
     * @return list<int>
     */
    private function candidateInstrumentIds(int $limit): array
    {
        return WatchlistItem::query()
            ->select('instrument_id')
            ->distinct()
            ->orderBy('instrument_id')
            ->limit($limit)
            ->pluck('instrument_id')
            ->all();
    }

    /**
     * 是否該跳過這一檔；非 null 時附帶人看得懂的原因。
     *
     * 這裡**不能**單靠呼叫端的 claim() 來判斷新鮮度：`succeeded` 終態的
     * `retry_after` 恆為 null（見 FetchFinancialStatements::finish()，只有
     * failed／unsupported 會設退避），claim() 的 terminal + retryDue 判準對
     * succeeded 永遠成立，若不在這裡先擋下，每次預熱都會把新鮮資料重抓一遍，
     * 白白耗用額度。unsupported／failed 的退避門檻則與 claim() 是同一把尺，
     * 這裡提前判斷純粹是為了印出對得上原因的訊息，不影響最終是否會打上游
     * （claim() 本身仍會對這兩態做同樣的把關）。
     *
     * succeeded 的新鮮度判準改用 FinancialStatementsReader::isStale()（跨列取
     * 最新、跨欄取最舊，比對表列的 *_fetched_at），不是這一列自己的
     * finished_at：後者只代表「job 什麼時候結束」，跟 spec 定義的「表列多久
     * 沒被成功刷新」是兩件事，兩把尺對同一筆資料可能給出不同答案
     * （FinancialStatementDispatcher::claim() 判斷 succeeded 該不該重派工時
     * 用的正是前者，這裡若繼續用 finished_at 會讓「預熱該不該跳過」與
     * 「瀏覽觸發該不該重派」互相矛盾）。完全沒有表列（抓成功但零期間，見
     * FetchFinancialStatements 對空 PeriodFactSet 記的 error_category='no_data'）
     * 時不能跳過，否則這種標的永遠沒有機會被重新預熱。
     */
    private function skipReason(?object $row, Instrument $instrument): ?string
    {
        if ($row === null) {
            return null;
        }

        if (in_array($row->status, ['queued', 'running'], true)) {
            return '擷取中';
        }

        if ($row->status === 'succeeded') {
            $periods = FinancialStatement::query()->where('instrument_id', $instrument->id)->get();

            if ($periods->isNotEmpty() && ! FinancialStatementsReader::isStale($periods)) {
                return '近期已成功，仍在新鮮期內';
            }

            return null;
        }

        if ($row->status === 'unsupported' || $row->status === 'failed') {
            $retryAfter = $row->retry_after !== null ? Carbon::parse($row->retry_after) : null;

            if ($retryAfter !== null && $retryAfter->greaterThan(now())) {
                return $row->status === 'unsupported' ? 'unsupported 退避未到期' : '退避未到期';
            }

            return null;
        }

        return null;
    }

    /**
     * 建立或認領一列 financial_statement_fetches，回傳可用的 generation。
     *
     * 邏輯照抄 {@see FinancialStatementDispatcher::claim()}
     * ／`insertIgnore()`：那兩個方法是 `private`，且不得修改 Task 7 的檔案，
     * 這裡是唯一能拿到同等 CAS 保證的方式。第一步用 INSERT IGNORE 而非 MySQL 的
     * ON DUPLICATE KEY UPDATE，理由同源：ODKU 不支援 WHERE，列已存在且為
     * running 時會被無條件覆寫成 queued，直接打穿 CAS。succeeded 的新鮮度檢查
     * 也照抄——目前唯一呼叫端 warmOne() 已經先被 skipReason() 擋掉新鮮的
     * succeeded，這裡按理不會被觸發，但這個方法本身沒有理由比 Dispatcher 的
     * 版本更寬鬆：CAS 邏輯本來就該是「複製」而非「複製其中一部分」，否則兩份
     * 拷貝各自維護、各自可能漏改的風險又回來了。
     *
     * @return int|null null = 不該處理（in-flight、終態但退避未到期，或
     *                  succeeded 仍在新鮮期內）
     */
    private function claim(Instrument $instrument): ?int
    {
        $table = (new FinancialStatementFetch)->getTable();
        $now = now()->toDateTimeString();

        if ($this->insertIgnore($table, $instrument->id, $now) === 1) {
            return 1;
        }

        return DB::transaction(function () use ($table, $instrument, $now): ?int {
            $row = DB::table($table)
                ->where('instrument_id', $instrument->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            $isTerminal = in_array($row->status, ['succeeded', 'failed', 'unsupported'], true);
            $retryDue = $row->retry_after === null
                || Carbon::parse($row->retry_after)->lessThanOrEqualTo(now());

            if (! $isTerminal || ! $retryDue) {
                return null;
            }

            if ($row->status === 'succeeded') {
                $periods = FinancialStatement::query()->where('instrument_id', $instrument->id)->get();

                if ($periods->isNotEmpty() && ! FinancialStatementsReader::isStale($periods)) {
                    return null;
                }
            }

            $newGeneration = $row->generation + 1;

            DB::table($table)
                ->where('id', $row->id)
                ->update([
                    'generation' => $newGeneration,
                    'status' => 'queued',
                    'queued_at' => $now,
                    'started_at' => null,
                    'finished_at' => null,
                    'error_category' => null,
                    'attempts' => 0,
                    'updated_at' => $now,
                ]);

            return $newGeneration;
        });
    }

    /** sqlite（測試）用 `INSERT OR IGNORE`，語意與 MySQL 的 `INSERT IGNORE` 相同。 */
    private function insertIgnore(string $table, int $instrumentId, string $now): int
    {
        $verb = DB::connection()->getDriverName() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        return DB::affectingStatement(
            "{$verb} INTO {$table}
                (instrument_id, generation, status, attempts, queued_at, created_at, updated_at)
             VALUES (?, 1, 'queued', 0, ?, ?, ?)",
            [$instrumentId, $now, $now, $now]
        );
    }
}
