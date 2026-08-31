<?php

namespace App\Services\FinancialStatements;

use App\Contracts\FinancialStatementSource;
use App\Data\FetchResult;
use App\Enums\DatasetStatus;
use App\Enums\FetchStatus;
use App\Support\FinMindGate;
use App\Support\FinMindTokenResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 台股：FinMind 的三個財報 dataset。
 *
 * **自帶 12 秒 timeout**，不共用既有 FinMindFundamentalsProvider 的 20 秒。
 * 那個 singleton 同時服務 FundamentalsProvider（估值）與 CompanyFinancialsProvider
 * （評級序列），改它的建構參數就改到舊鏈路的失敗時機，與本專案的隔離前提衝突。
 *
 * 3 × 12 = 36 秒，留得下 InlineQueueWorker 的 60 秒上限。
 *
 * 沿用既有的 FinMindGate 全站冷卻（只讀，不修改該類別）：免費層額度是跨
 * provider 共用的，這層若不理會冷卻，會在額度已耗盡時繼續加壓，也會讓
 * 用同一 token 的其他 provider 被這裡的請求拖進封鎖。
 */
class FinMindFinancialStatementSource implements FinancialStatementSource
{
    private const ENDPOINT = 'https://api.finmindtrade.com/api/v4/data';

    public function __construct(
        private readonly FinMindTokenResolver $tokens,
        private readonly FinMindNormalizer $normalizer,
    ) {}

    public function fetch(string $symbol, int $quarters, int $years): FetchResult
    {
        $dataId = explode('.', $symbol)[0];
        $start = now()->subYears(max(1, $years))->toDateString();

        $datasets = (array) config('financial_statements.finmind_datasets');
        $rows = [];
        $statuses = [];

        foreach ($datasets as $group => $dataset) {
            $result = $this->rows($dataset, $dataId, $start);

            if (! $result['ok']) {
                // fail-fast：任一 dataset 失敗即中止整批，不繼續發後續請求。
                // 只降 timeout 不 fail-fast 的話，最壞情況仍會耗盡 worker 的預算。
                $statuses[$dataset] = DatasetStatus::Failed;

                return FetchResult::failed($result['category'], $statuses);
            }

            $rows[$group] = $result['rows'];
            $statuses[$dataset] = $rows[$group] === [] ? DatasetStatus::Empty : DatasetStatus::Ok;
        }

        $periods = $this->normalizer->normalize(
            $rows['income'] ?? [],
            $rows['balance'] ?? [],
            $rows['cashflow'] ?? [],
            $quarters,
            $years,
        );

        if ($periods->isEmpty()) {
            // 三個 dataset 都呼叫成功卻沒有任何財報：ETF、下市標的等，永久不支援。
            return FetchResult::unsupported('no_statements', $statuses);
        }

        return new FetchResult(FetchStatus::Complete, $periods, $statuses);
    }

    /**
     * 呼叫單一 dataset，回傳可分類的結果——不用 null，是因為呼叫端與測試都需要
     * 區分「為什麼失敗」（逾時／HTTP 錯誤／API 層錯誤／額度耗盡），與 SEC 側的
     * errorCategory 保持同一種粒度。
     *
     * @return array{ok: bool, category: ?string, rows: list<array<string, mixed>>}
     */
    private function rows(string $dataset, string $dataId, string $start): array
    {
        // 免費層額度冷卻中：跳過，不再對已耗盡的額度加壓。
        if (FinMindGate::isTripped()) {
            return ['ok' => false, 'category' => 'gate_tripped', 'rows' => []];
        }

        try {
            $response = Http::timeout((int) config('financial_statements.finmind_timeout_seconds'))
                ->get(self::ENDPOINT, array_filter([
                    'dataset' => $dataset,
                    'data_id' => $dataId,
                    'start_date' => $start,
                    'token' => $this->tokens->resolve(),
                ]));
        } catch (Throwable) {
            // 逾時／DNS／連線被拒都是暫時性的，值得重試。
            return ['ok' => false, 'category' => 'unreachable', 'rows' => []];
        }

        if (FinMindGate::limited($response)) {
            // 402/429 或 msg 含額度／付費牆字樣：開啟全站冷卻，讓用同一 token
            // 的其他 provider 也跳過，而不是各自繼續撞已耗盡的額度。
            return ['ok' => false, 'category' => 'quota_exceeded', 'rows' => []];
        }

        if (! $response->successful()) {
            // 一般 429／403／5xx。FinMindGate::limited() 判定為額度耗盡以外的都在這裡。
            return ['ok' => false, 'category' => 'http_'.$response->status(), 'rows' => []];
        }

        $body = $response->json();

        if (! is_array($body)) {
            // HTTP 200 也可能是 HTML 錯誤頁、壞 JSON。
            return ['ok' => false, 'category' => 'malformed', 'rows' => []];
        }

        // FinMind 的 HTTP 200 + 空 data 可能是額度用盡或參數錯誤。
        // 先驗 API 層的 status，否則會被誤判成「這檔沒有財報」而卡成 unsupported。
        $apiStatus = $body['status'] ?? null;

        if ((int) $apiStatus !== 200) {
            return ['ok' => false, 'category' => 'api_status_'.($apiStatus === null ? 'missing' : (string) $apiStatus), 'rows' => []];
        }

        return ['ok' => true, 'category' => null, 'rows' => is_array($body['data'] ?? null) ? $body['data'] : []];
    }
}
