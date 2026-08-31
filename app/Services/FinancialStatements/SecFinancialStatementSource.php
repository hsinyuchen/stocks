<?php

namespace App\Services\FinancialStatements;

use App\Contracts\FinancialStatementSource;
use App\Data\FetchResult;
use App\Enums\DatasetStatus;
use App\Enums\FetchStatus;
use App\Services\FinancialStatements\Sec\SecNormalizer;
use App\Services\Fundamentals\SecTickerCikResolver;
use Illuminate\Support\Facades\Cache;
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
    /**
     * 與 SecTickerCikResolver::CACHE_KEY（該檔的 private const）**必須是同一個
     * 字串**。之所以在這裡重複寫死而不是改 resolver 開放存取：resolver 在
     * app/Services/Fundamentals/ 下，是本子專案的隔離禁區（只讀，不得修改），
     * 讀取它私有的快取鍵是本層唯一能做到「區分暫時性故障與永久不支援」的
     * 方法——見下面 cikMapAvailable() 的說明。
     *
     * 脆弱點：resolver 若哪天改了這個快取鍵，這裡會悄悄退化成「永遠判定對照表
     * 可用」（Cache::get() 拿到 null，is_array() 為 false，cikMapAvailable()
     * 回 false，行為等同修復前）——不會出現例外或明顯錯誤，只會讓這個修復
     * 靜默失效。改動 resolver 的快取鍵時務必同步檢查這裡。
     */
    private const CIK_MAP_CACHE_KEY = 'sec:ticker_cik_map';

    public function __construct(
        private readonly SecTickerCikResolver $resolver,
        private readonly SecNormalizer $normalizer,
    ) {}

    public function fetch(string $symbol, int $quarters, int $years): FetchResult
    {
        $cik = $this->resolver->resolve($symbol);

        if ($cik === null) {
            if (! $this->cikMapAvailable()) {
                // 對照表本身抓不到（SEC 或網路短暫故障）：resolve() 回 null 與
                // 「這檔真的沒有 CIK」在 resolver 這層完全無法區分（resolver
                // 抓不到時仍會寫入空 map 並快取 10 分鐘）。判成 unsupported 會
                // 讓這 10 分鐘內每一檔美股都被永久判定不支援——這是一次全市場
                // 誤封，而不是這檔標的真的不支援，值得重試。
                return FetchResult::failed('cik_map_unavailable', ['companyfacts' => DatasetStatus::Failed]);
            }

            // 對照表可用（非空）但查無此代號：指數與多數 ETF 都落在這裡，永久不支援。
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
     * ticker→CIK 對照表本身是否可用（非空）。
     *
     * SecTickerCikResolver 的快取只存一個扁平陣列，抓取失敗與「上游真的回傳
     * 空表」在快取裡是同一個值（空陣列），無法從快取本身分辨成因。實務上
     * SEC 的官方對照表恆有上萬筆，永遠不會是空的，所以「快取是空陣列」在
     * 生產環境等同於「這次沒抓到」，可以放心當成「不可用」處理。
     */
    private function cikMapAvailable(): bool
    {
        $map = Cache::get(self::CIK_MAP_CACHE_KEY);

        return is_array($map) && $map !== [];
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
