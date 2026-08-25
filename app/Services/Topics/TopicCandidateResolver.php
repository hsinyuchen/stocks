<?php

namespace App\Services\Topics;

use App\Data\TopicBoard;
use App\Data\TopicCandidate;
use App\Enums\AssetType;
use App\Enums\MarketRegion;
use App\Enums\RevenueUnknownReason;
use App\Enums\TopicDirection;
use App\Enums\TopicTier;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\FundamentalsService;
use App\Services\Fundamentals\OrderInventoryAssessor;
use App\Support\MarketResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 把三個來源合成一份題材候選清單：人工策展的傳導表（核心）、同產業的已快取
 * 標的（延伸）、新聞共同提及（外圍）。
 *
 * **全程只讀已快取資料，一次上游都不打。** 營收驗證走
 * {@see OrderInventoryAssessor::seriesSignalsFor()}、產業別走
 * {@see FundamentalsService::orderInventorySeriesFor()}，兩者都用序列自己的
 * 新鮮度視窗、不寫入、不抓。
 *
 * **不得改用 `forInstrument()`**（美股打 SEC EDGAR、timeout 40 秒、沒有
 * FinMindGate 那種斷路器）**或 `cachedFor()`**（用估值的每日 TTL 判過期，
 * 且會寫回評級）。這個約束在測試上是無聲的——只有那條綁 HTTP spy 並計數的
 * 測試會紅，`Http::assertNothingSent()` 在本專案的 fake driver 下恆成立。
 * 兩者的失效模式不同，要兩條測試才蓋得住：改用 `forInstrument()` 會多打上游
 * （呼叫計數會紅）；改用 `cachedFor()` 一個上游都不打，但它換了一把尺、
 * 又會寫回評級（三態測試與「不寫回評級」那條會紅）。
 *
 * **層級不含營收驗證。** 層級講「關聯有多硬」，營收驗證講「有沒有財務佐證」，
 * 見 {@see TopicTier}。「僅新聞提及且查無任何佐證」不是第四個層級，而是外圍層
 * 裡 `industry` 與 `revenueVerified` 都是 null 的那些列——硬立成一個桶等於把
 * 同一件事編碼兩次，桶名與徽章遲早漂移。
 */
class TopicCandidateResolver
{
    public function __construct(
        private readonly TopicNewsMentions $mentions,
        private readonly OrderInventoryAssessor $orderInventory,
        private readonly FundamentalsService $fundamentals,
    ) {}

    /**
     * 供題材選擇畫面使用。**不預設任何一個題材**——八個題材之間沒有
     * 「哪個比較重要」的依據，替使用者選一個等於系統擅自下了判斷。
     *
     * @return list<array{key: string, label: string}>
     */
    public function availableTopics(): array
    {
        $out = [];

        foreach ((array) config('news.transmission', []) as $rule) {
            $key = (string) ($rule['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $out[] = ['key' => $key, 'label' => (string) ($rule['label'] ?? $key)];
        }

        return $out;
    }

    /**
     * 未知題材回 null 而不是空 board：呼叫端要能分辨「這個題材沒有候選」
     * 與「根本沒有這個題材」，前者顯示空清單、後者回到題材選擇畫面。
     */
    public function resolve(string $topicKey, ?CarbonImmutable $now = null): ?TopicBoard
    {
        $rule = $this->rule($topicKey);

        if ($rule === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();

        // 一次查出已知的非個股，三層共用同一份：分三次查會讓三層各自漂移，
        // 而「哪些標的不是個股」對同一份 board 只能有一個答案。
        $nonStock = $this->nonStockSymbols();

        // 核心先解，因為延伸要用核心的 industry。
        $core = $this->core($rule, $nonStock);
        $extended = $this->extended($core);

        $taken = array_merge(array_keys($core), array_keys($extended));
        $periphery = $this->periphery($topicKey, $taken, $now, $nonStock);

        $candidates = array_merge(array_values($core), array_values($extended), $periphery);

        return new TopicBoard(
            key: $topicKey,
            label: (string) ($rule['label'] ?? $topicKey),
            // chain 逐句照 config 原文，不改寫也不截斷：那是這個題材的因果假設，
            // 使用者要看得出它長什麼樣才能判斷要不要信。
            chain: array_values(array_map('strval', (array) ($rule['chain'] ?? []))),
            candidates: $candidates,
            windowDays: $this->requireInt('window_days'),
            minMentions: $this->requireInt('min_mentions'),
        );
    }

    /** @return array<string, mixed>|null */
    private function rule(string $topicKey): ?array
    {
        foreach ((array) config('news.transmission', []) as $rule) {
            if ((string) ($rule['key'] ?? '') === $topicKey) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * 傳導表列名的個股。
     *
     * 方向取 config 的**宣告值**，不取 TransmissionMapper 被新聞極性翻轉過的值
     * ——理由見 {@see TopicDirection}。
     *
     * 同一檔若出現在多個 sector，**取第一次出現的那個**：目前 config 沒有這種
     * 情形，但也沒有禁止。取第一個而不是合併，是因為「同時受惠又受衝擊」對
     * 使用者沒有可行動的意義，而挑一個至少是可解釋的（傳導表的排列順序就是
     * 作者的敘事順序）。
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, true>  $nonStock
     * @return array<string, TopicCandidate> symbol => 候選
     */
    private function core(array $rule, array $nonStock): array
    {
        $rows = [];

        foreach ((array) ($rule['sectors'] ?? []) as $sector) {
            $direction = TopicDirection::fromDeclared((string) ($sector['direction'] ?? ''));
            $sectorName = (string) ($sector['name'] ?? '');

            foreach ((array) ($sector['symbols'] ?? []) as $symbol) {
                $symbol = (string) $symbol;

                if ($symbol === '' || isset($rows[$symbol]) || isset($nonStock[$symbol])) {
                    continue;
                }

                $rows[$symbol] = ['direction' => $direction, 'sector' => $sectorName];
            }
        }

        $instruments = $this->instruments(array_keys($rows));
        $out = [];

        foreach ($rows as $symbol => $meta) {
            $instrument = $instruments->get($symbol);
            [$revenueVerified, $revenueUnknownReason] = $this->revenueSignals($instrument);

            $out[$symbol] = new TopicCandidate(
                symbol: $symbol,
                name: $instrument?->name,
                tier: TopicTier::Core,
                direction: $meta['direction'],
                revenueVerified: $revenueVerified,
                revenueUnknownReason: $revenueUnknownReason,
                industry: $this->industryOf($instrument),
                sectorName: $meta['sector'] === '' ? null : $meta['sector'],
            );
        }

        return $out;
    }

    /**
     * 與某個核心標的同產業的其他已快取標的。
     *
     * **僅台股**：美股的 `industry` 恆為 null（階段 1 決定不抓 SIC），所以美股
     * 核心不會延伸出任何東西。這與「同產業但沒有其他標的」語意不同，
     * 呈現層靠核心列的 `industry` 是否為 null 來分辨。
     *
     * 上限是**延伸層的總數上限，不是每個方向各 N**。截斷前先依 symbol 排序，
     * 讓同一份資料每次得到同一份清單——使用者重新整理不該換一批。
     *
     * @param  array<string, TopicCandidate>  $core
     * @return array<string, TopicCandidate>
     */
    private function extended(array $core): array
    {
        /** @var array<string, ?TopicDirection> $byIndustry */
        $byIndustry = [];

        foreach ($core as $candidate) {
            if ($candidate->industry === null || $candidate->industry === '') {
                continue;
            }

            // 同一個產業可能對應多個核心、且方向可能不同（實測：台股「航運業」
            // 同時含 2603 受惠與 2610 受衝擊）。取第一個核心的方向，理由同
            // core()：合併成「雙向」對使用者沒有可行動的意義。
            //
            // 用 array_key_exists 而非 `??=`：方向本身可以是 null（全 neutral
            // 的題材），`??=` 會讓後來的非 null 方向覆蓋掉第一個，那就不是
            // 「取第一個」了。
            if (! array_key_exists($candidate->industry, $byIndustry)) {
                $byIndustry[$candidate->industry] = $candidate->direction;
            }
        }

        if ($byIndustry === []) {
            return [];
        }

        $exclude = array_keys($core);
        $collected = [];

        foreach ($byIndustry as $industry => $direction) {
            foreach ($this->sameIndustry((string) $industry, $exclude) as $symbol => $instrument) {
                // 已被別的產業收走就不重複——先到先得，順序由 $byIndustry 決定。
                $collected[$symbol] ??= ['instrument' => $instrument, 'direction' => $direction, 'industry' => (string) $industry];
            }
        }

        ksort($collected);
        $collected = array_slice($collected, 0, $this->requireInt('max_extended'), true);

        $out = [];

        foreach ($collected as $symbol => $meta) {
            [$revenueVerified, $revenueUnknownReason] = $this->revenueSignals($meta['instrument']);

            $out[$symbol] = new TopicCandidate(
                symbol: (string) $symbol,
                name: $meta['instrument']->name,
                tier: TopicTier::Extended,
                direction: $meta['direction'],
                revenueVerified: $revenueVerified,
                revenueUnknownReason: $revenueUnknownReason,
                industry: $meta['industry'],
                // 延伸不屬於任何被策展的 sector。帶上 sector 名稱會讓使用者
                // 以為它也被策展進了那一段傳導。
                sectorName: null,
            );
        }

        return $out;
    }

    /**
     * 同市場同產業、每檔取最新一列。
     *
     * 產業述詞推進 SQL（JSON 路徑查詢，MySQL 與 SQLite 皆可編譯），否則這裡是
     * 無述詞的全表載入：`fundamentals` 的索引前導欄是 `instrument_id`，單獨過濾
     * 時間欄用不到索引，而每列的 order_inventory JSON（約 10 季 × 16 欄位 +
     * 約 30 個月營收點）都會被 hydrate。理由與
     * OrderInventoryIndustrySampler::scanIndustry() 相同。
     *
     * 新鮮度用 `order_inventory.series_freshness_days`，與
     * FundamentalsService::orderInventorySeriesFor() **同一把尺**：同一份
     * order_inventory 用兩把尺會出現「標的自己看得到、同業看不到」的不對稱。
     *
     * @param  list<string>  $exclude
     * @return array<string, Instrument>
     */
    private function sameIndustry(string $industry, array $exclude): array
    {
        $floor = CarbonImmutable::now()
            ->subDays((int) config('order_inventory.series_freshness_days'))
            ->startOfDay();

        $rows = Fundamental::query()
            ->join('instruments', 'instruments.id', '=', 'fundamentals.instrument_id')
            ->whereNotNull('fundamentals.order_inventory')
            ->where('fundamentals.order_inventory->industry', $industry)
            ->where('instruments.asset_type', AssetType::Stock->value)
            ->where('fundamentals.fetched_at', '>=', $floor)
            ->whereNotIn('instruments.symbol', $exclude)
            ->orderByDesc('fundamentals.fetched_at')
            ->orderByDesc('fundamentals.id')
            ->get(['instruments.id', 'instruments.symbol', 'instruments.name']);

        $out = [];

        foreach ($rows as $row) {
            $symbol = (string) $row->symbol;

            // 同一檔可能有多列（每個資料日一列），最新那列先出現，之後的略過。
            if (isset($out[$symbol])) {
                continue;
            }

            // 產業別只有台股有意義，這裡再擋一次：JSON 裡的 industry 是反正規化
            // 快照，理論上不會出現在美股列上，但拿它做跨公司比較前先驗一次。
            if (MarketResolver::region($symbol) !== MarketRegion::Taiwan) {
                continue;
            }

            $instrument = new Instrument;
            $instrument->id = $row->id;
            $instrument->symbol = $symbol;
            $instrument->name = $row->name;

            $out[$symbol] = $instrument;
        }

        return $out;
    }

    /**
     * 新聞共同提及達門檻的標的，扣掉已在核心與延伸的。
     *
     * **不做 top-N 保底**：達不到門檻就是空的。保底等於系統對一則提及的標的
     * 宣稱「這檔與這個題材有關」。
     *
     * @param  list<string>  $exclude
     * @param  array<string, true>  $nonStock
     * @return list<TopicCandidate>
     */
    private function periphery(string $topicKey, array $exclude, CarbonImmutable $now, array $nonStock): array
    {
        $min = $this->requireInt('min_mentions');
        $max = $this->requireInt('max_periphery');
        $skip = array_flip($exclude);

        // forTopic() 已依次數遞減排序，這裡不再排。
        $counts = $this->mentions->forTopic($topicKey, $now);
        $picked = [];

        foreach ($counts as $symbol => $count) {
            if (count($picked) >= $max) {
                break;
            }

            // 非個股在計數上限**之前**就剔除：留到之後再篩，指數會先佔掉一個
            // 名額，使用者拿到的是一份少一檔的清單而不是同樣長度的乾淨清單。
            if ($count < $min || isset($skip[$symbol]) || isset($nonStock[$symbol])) {
                continue;
            }

            $picked[$symbol] = $count;
        }

        $instruments = $this->instruments(array_keys($picked));
        $out = [];

        foreach ($picked as $symbol => $count) {
            $instrument = $instruments->get($symbol);
            [$revenueVerified, $revenueUnknownReason] = $this->revenueSignals($instrument);

            $out[] = new TopicCandidate(
                symbol: (string) $symbol,
                name: $instrument?->name,
                tier: TopicTier::Periphery,
                // 外圍不在傳導表內，系統不知道方向。不給方向不是資料缺漏，
                // 是這個層級本來就沒有這個資訊。
                direction: null,
                revenueVerified: $revenueVerified,
                revenueUnknownReason: $revenueUnknownReason,
                industry: $this->industryOf($instrument),
                mentionCount: $count,
            );
        }

        return $out;
    }

    /**
     * 已知**不是個股**的標的：指數與 ETF。
     *
     * 大盤指數與任何總體題材共同提及是結構性的，不是訊號——一則談升息的新聞
     * 幾乎必提 ^GSPC，而那不代表 S&P 500 是這個題材的候選。ETF 同理：使用者
     * 點進候選要看的是一檔可分析的個股，不是一籃子標的。
     *
     * 反向列舉（列出非個股）而不是正向過濾 `asset_type = stock`：傳導表有 30 檔
     * 而 instruments 表只有 20 檔，**不在表裡的照樣要列出**（建立標的是 ingest
     * 與搜尋的職責）。正向過濾會把「查無此標的」與「查到了但不是個股」壓成同
     * 一件事，前者該留、後者該丟。
     *
     * @return array<string, true>
     */
    private function nonStockSymbols(): array
    {
        $symbols = Instrument::query()
            ->where('asset_type', '!=', AssetType::Stock->value)
            ->pluck('symbol')
            ->all();

        return array_fill_keys(array_map('strval', $symbols), true);
    }

    /**
     * @param  list<string>  $symbols
     * @return Collection<string, Instrument>
     */
    private function instruments(array $symbols): Collection
    {
        if ($symbols === []) {
            return collect();
        }

        return Instrument::query()->whereIn('symbol', $symbols)->get()->keyBy('symbol');
    }

    /**
     * 營收驗證的三態，以及沒有結論時的成因。
     *
     * `revenueVerified` 為 null 不是「未驗證」而是「沒有結論」，成因見
     * {@see RevenueUnknownReason}：其中兩種（本框架不適用此產業、標的不在
     * instruments 表）**不會因為再跑一次分析或掃描而改變**，實測傳導表 30 檔裡
     * 有 10 檔屬後者，hormuz_oil 的九檔核心裡就佔六檔。全部顯示成「無資料」
     * 會讓使用者一直等一個不會來的東西。
     *
     * 標的不在 instruments 表時回 {@see RevenueUnknownReason::NotInUniverse}：
     * 那時連產業都還不知道，宣稱本框架不適用是沒有證據的結論。
     *
     * 本方法與 {@see industryOf()} 對同一檔各取一次序列（seriesSignalsFor()
     * 內部也會呼叫 orderInventorySeriesFor()），刻意**不合併**。量測（本機、
     * sqlite、生產尺寸的序列＝10 季 + 30 個月營收點）：重複那一次是 1.06ms，
     * seriesSignalsFor() 自己是 3.92ms，整份滿額 board（9 核心 + 20 延伸 +
     * 20 外圍）多付約 31ms，而 SQL 只佔 79 次點查合計 5.6ms——成本幾乎全在
     * DTO hydrate，不在資料庫。要省掉它，就得在這裡自己呼叫 OrderInventoryRadar
     * 並複製 seriesSignalsFor() 那段「必須走完整 assess() 才不會用一份已被判定
     * 不可評級的序列宣稱營收已驗證」的短路判斷，等於製造第二份必然漂移的副本。
     * 31ms 換不到那個風險。
     *
     * @return array{0: ?bool, 1: ?RevenueUnknownReason}
     */
    private function revenueSignals(?Instrument $instrument): array
    {
        if ($instrument === null) {
            return [null, RevenueUnknownReason::NotInUniverse];
        }

        $signals = $this->orderInventory->seriesSignalsFor($instrument);

        return [$signals['revenue_verified'], $signals['revenue_unknown_reason']];
    }

    private function industryOf(?Instrument $instrument): ?string
    {
        if ($instrument === null) {
            return null;
        }

        return $this->fundamentals->orderInventorySeriesFor($instrument)?->industry;
    }

    /**
     * 嚴格取值。裸 `(int) config(...)` 缺鍵時會靜默變 0，而這幾個鍵的 0 都會
     * 無聲改變清單內容：`min_mentions` 為 0 讓一則提及也進榜、`max_*` 為 0
     * 讓整層空掉。三者都沒有任何錯誤訊號可供察覺。
     */
    private function requireInt(string $key): int
    {
        $value = config("topics.{$key}");

        if (! is_numeric($value)) {
            throw new \RuntimeException("topics.{$key} config 缺失或非數值。");
        }

        return (int) $value;
    }
}
