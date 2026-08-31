<?php

namespace App\Services\FinancialStatements;

use App\Contracts\FinancialStatementSource;
use App\Data\FetchResult;
use App\Enums\DatasetStatus;
use App\Enums\FetchStatus;
use App\Services\FinancialStatements\Sec\SecNormalizer;
use App\Services\Fundamentals\SecTickerCikResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 美股：SEC EDGAR companyfacts。
 *
 * 只負責 HTTP 與把結果分類成 FetchResult；期間識別全部在 SecNormalizer。
 *
 * 分類的順序很重要：**暫時性失敗必須與永久不支援分開**。既有的 provider 把
 * 429／403／5xx 與「這檔真的沒有財報」一律壓成空陣列
 * （SecEdgarFinancialsProvider.php:116-131），狀態層無從區分，於是只能對兩者
 * 做同一種處置——不是無限重試，就是把可用的標的錯誤地封鎖住。
 *
 * asset type gate 不在這一層：本契約收的是 string $symbol，讀不到
 * `Instrument::asset_type`。對指數與多數 ETF 的保護只有一種形式：
 * resolve() 拿不到 CIK → unsupported 且不重試。
 */
class SecFinancialStatementSource implements FinancialStatementSource
{
    public function __construct(
        private readonly SecTickerCikResolver $resolver,
        private readonly SecNormalizer $normalizer,
    ) {}

    public function fetch(string $symbol, int $quarters, int $years): FetchResult
    {
        $cik = $this->resolver->resolve($symbol);

        if ($cik === null) {
            // 沒有 CIK 就不會有 SEC 財報。指數與多數 ETF 都落在這裡，永久不支援。
            return FetchResult::unsupported('no_cik', ['companyfacts' => DatasetStatus::Unsupported]);
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('order_inventory.sec.user_agent'),
                'Accept-Encoding' => 'gzip, deflate',
            ])
                ->timeout((int) config('financial_statements.sec_timeout_seconds'))
                ->get(str_replace('{cik}', $cik, (string) config('order_inventory.sec.company_facts_url')));
        } catch (Throwable) {
            // 逾時／DNS／連線被拒都是暫時性的，值得重試。
            return FetchResult::failed('unreachable', ['companyfacts' => DatasetStatus::Failed]);
        }

        if ($response->status() === 404) {
            // SEC 對「這個 CIK 從未申報任何 XBRL 資料」回 404——語意上等同於下面
            // 「結構合法但沒有目標科目」的 unsupported，而不是可重試的暫時性錯誤：
            // 重試不會改變結果，只是白打 SEC 直到這家公司真的申報為止。
            return FetchResult::unsupported('not_found', ['companyfacts' => DatasetStatus::Unsupported]);
        }

        if (! $response->successful()) {
            // 429／403／5xx 全是暫時性的。判成 unsupported 會把標的錯誤地卡住 7 天。
            return FetchResult::failed('http_'.$response->status(), ['companyfacts' => DatasetStatus::Failed]);
        }

        $facts = $response->json();

        if (! is_array($facts) || ! isset($facts['facts']) || ! is_array($facts['facts'])) {
            // HTTP 200 也可能是 HTML 錯誤頁、壞 JSON、或缺 facts 結構。
            return FetchResult::failed('malformed', ['companyfacts' => DatasetStatus::Failed]);
        }

        if ((int) ($facts['cik'] ?? 0) !== (int) $cik) {
            // 不比對 entityName：公司更名與簡稱本來就與 Instrument::name 不一致，
            // 拿它當判準只會製造假失敗。
            return FetchResult::failed('cik_mismatch', ['companyfacts' => DatasetStatus::Failed]);
        }

        if (! $this->hasTargetFields($facts)) {
            return FetchResult::unsupported('no_us_gaap', ['companyfacts' => DatasetStatus::Unsupported]);
        }

        $periods = $this->normalizer->normalize($facts, $quarters, $years);

        return new FetchResult(
            status: FetchStatus::Complete,
            periods: $periods,
            datasetStatuses: ['companyfacts' => $periods->isEmpty() ? DatasetStatus::Empty : DatasetStatus::Ok],
        );
    }

    /**
     * 是否有**目標科目**的 USD／USD-per-shares 資料。
     *
     * 判「有任一個 USD 單位」是不夠的：只申報少量無關 us-gaap facts 的 ETF
     * 會被誤判成可支援的公司，然後每次都產出空表。
     */
    private function hasTargetFields(array $facts): bool
    {
        $gaap = $facts['facts']['us-gaap'] ?? null;

        if (! is_array($gaap) || $gaap === []) {
            return false;
        }

        $targets = [];

        foreach ((array) config('financial_statements.sec_tags') as $tags) {
            foreach ($tags as $tag) {
                $targets[$tag] = 'USD';
            }
        }

        foreach ((array) config('financial_statements.sec_eps_tags') as $tags) {
            foreach ($tags as $tag) {
                $targets[$tag] = 'USD/shares';
            }
        }

        foreach ($targets as $tag => $unit) {
            if (! empty($gaap[$tag]['units'][$unit])) {
                return true;
            }
        }

        return false;
    }
}
