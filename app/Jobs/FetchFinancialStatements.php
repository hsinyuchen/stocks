<?php

namespace App\Jobs;

use App\Contracts\FinancialStatementSource;
use App\Enums\AssetType;
use App\Enums\FetchStatus;
use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use App\Services\FinancialStatements\FinancialStatementWriter;
use App\Services\FinancialStatements\TaiwanAnnualDeriver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 抓一檔標的的財報並落地。
 *
 * 刻意**不用** ShouldBeUnique：它會與 generation 互鎖成死結——新請求先把 DB 改成
 * generation N+1／queued，新 dispatch 被 unique lock 靜默拒絕（PendingDispatch
 * ::__destruct() 在 shouldDispatch() 回 false 時只是 return，不拋任何例外，呼叫端
 * 無從得知），舊 job 又因 generation 不匹配而退出，於是永久卡在 queued。
 * generation 是唯一的去重與版本機制。
 */
class FetchFinancialStatements implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $backoff;

    public int $timeout;

    public function __construct(
        public readonly int $instrumentId,
        public readonly int $generation,
    ) {
        $config = (array) config('financial_statements.job');

        $this->tries = (int) $config['tries'];
        $this->backoff = (int) $config['backoff'];
        $this->timeout = (int) $config['timeout'];
        $this->onQueue((string) $config['queue']);
    }

    public function handle(
        FinancialStatementSource $source,
        FinancialStatementWriter $writer,
        TaiwanAnnualDeriver $deriver,
    ): void {
        $fetch = FinancialStatementFetch::where('instrument_id', $this->instrumentId)->first();
        $instrument = Instrument::find($this->instrumentId);

        if ($fetch === null || $instrument === null) {
            return;
        }

        // 允許 queued → running 與 running → running（後者是 Laravel 自動 retry 的
        // 第二次 attempt）。CAS 沒中就是這個 generation 已經過時，直接退出。
        if (! $fetch->markRunning($this->generation)) {
            return;
        }

        // 第一道 gate：asset_type 明確不是 stock 就不打任何上游。指數沒有 CIK、
        // ETF 不申報公司財報，這是永久不支援。
        if ($instrument->asset_type !== null && $instrument->asset_type !== AssetType::Stock) {
            $this->finish($fetch, FetchStatus::Unsupported, 'asset_type');

            return;
        }

        // 第二道 gate 在 source 裡：asset_type 是 stock 也不代表真的是股票
        // （搜尋與 watchlist 建列路徑都硬寫 stock，ETF 會帶著 stock 穿過第一道
        // gate），拿不到 CIK 時由它判 unsupported。
        $result = $source->fetch(
            $instrument->symbol,
            (int) config('financial_statements.quarters'),
            (int) config('financial_statements.years'),
        );

        if ($result->status === FetchStatus::Partial) {
            // 兩個 source 目前都不產出 Partial。真的收到代表擷取層改了行為，
            // 記下來讓人看見，並當 failed 處理（下次瀏覽會重試）。
            Log::warning('financial statements: 收到未預期的 Partial', [
                'instrument_id' => $this->instrumentId,
            ]);
            $this->finish($fetch, FetchStatus::Failed, 'partial');

            return;
        }

        if ($result->status !== FetchStatus::Complete) {
            $this->finish($fetch, $result->status, $result->errorCategory);

            return;
        }

        $market = $result->periods->market;

        if ($market === null) {
            // PeriodFactSet::$market 型別上是 nullable，但兩個既有 source
            // （SecNormalizer／FinMindNormalizer）在 Complete 一定會填。這裡守的是
            // 型別系統守不住的縫——沒有這道檢查，下面的三元運算式會把未知 market
            // 靜默標成 'sec'，寫壞 financial_statements.source 的 provenance。
            Log::error('financial statements: Complete 結果缺少 market，無法判定資料來源', [
                'instrument_id' => $this->instrumentId,
            ]);
            $this->finish($fetch, FetchStatus::Failed, 'unknown_market');

            return;
        }

        $periods = $deriver->derive($result->periods);
        $sourceName = $market === 'tw' ? 'finmind' : 'sec';

        // generation 必須 fencing 財報資料寫入，不只狀態列：遲到的 worker 會照樣
        // 把舊資料寫進 financial_statements，只是最後那步終態 CAS 失敗——資料早就
        // 壞了。所以寫入與終態 CAS 同一個交易，開頭先鎖住狀態列再確認一次。
        DB::transaction(function () use ($fetch, $instrument, $periods, $sourceName, $writer): void {
            $locked = FinancialStatementFetch::query()
                ->where('id', $fetch->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null
                || $locked->generation !== $this->generation
                || $locked->status !== 'running') {
                return;   // 交易內什麼都沒做，等同 rollback
            }

            $writer->write($instrument, $periods, $sourceName);

            // Complete 但零期間（剛上市、財報尚未揭露）與「從沒抓過」在狀態列
            // 上原本無法區分：兩者都是「表裡沒有列」。error_category 借來標記
            // 這個特例，讓 Reader／Dispatcher 能分辨「成功但沒資料，值得下次
            // 重試」與「什麼都沒發生過」，不需要新增第七種終態。
            $locked->markTerminal(
                $this->generation,
                'succeeded',
                $periods->isEmpty() ? 'no_data' : null,
            );
        });
    }

    /**
     * 終態 ＋ 退避。unsupported 給 7 天而不是永久——指數與 ETF 不會變，但「剛上市、
     * 還沒申報第一份 10-K」的公司會變，永久封鎖等於讓它們永遠看不到財報。
     */
    private function finish(FinancialStatementFetch $fetch, FetchStatus $status, ?string $category): void
    {
        $retry = match ($status) {
            FetchStatus::Unsupported => now()->addDays((int) config('financial_statements.retry_after.unsupported_days')),
            default => now()->addMinutes((int) config('financial_statements.retry_after.failed_minutes')),
        };

        $fetch->markTerminal(
            $this->generation,
            $status === FetchStatus::Unsupported ? 'unsupported' : 'failed',
            $category,
            $retry,
        );
    }

    /**
     * Laravel 在最後一次 attempt 也失敗時呼叫。沒有這個 handler，job 會留在
     * failed_jobs 而狀態列永遠停在 running，只能等 reaper 收割。
     */
    public function failed(?Throwable $exception): void
    {
        $fetch = FinancialStatementFetch::where('instrument_id', $this->instrumentId)->first();

        $fetch?->markTerminal(
            $this->generation,
            'failed',
            'exception',
            now()->addMinutes((int) config('financial_statements.retry_after.failed_minutes')),
        );
    }
}
