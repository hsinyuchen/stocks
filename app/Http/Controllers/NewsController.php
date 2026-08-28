<?php

namespace App\Http\Controllers;

use App\Models\FeedHealth;
use App\Models\LlmProviderSetting;
use App\Models\NewsAnalysis;
use App\Models\NewsItem;
use App\Models\User;
use App\Services\News\TransmissionMapper;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewsController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'market' => ['nullable', 'string', 'max:16'],
            'domain' => ['nullable', 'string', 'max:32'],
            'kind' => ['nullable', 'string', 'in:article,video'],
            'source' => ['nullable', 'string', 'max:120'],
            'symbol' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'include_irrelevant' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        // 預設濾掉與投資無關的雜訊（美食、社會案件、生活理財）。分類器的判定
        // 很保守：只有「無領域 ∧ 無個股 ∧ 命中排除字」三者同時成立才會標記，
        // 所以誤殺風險低。仍保留 include_irrelevant=1 供人工檢查誤判。
        $includeIrrelevant = (bool) ($filters['include_irrelevant'] ?? false);

        $items = NewsItem::query()
            ->unless($includeIrrelevant, fn (Builder $query) => $query->where('relevant', true))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->when(($filters['market'] ?? '') !== '', fn (Builder $query) => $query->where('market', $filters['market']))
            ->when(($filters['domain'] ?? '') !== '', fn (Builder $query) => $query->where('domain', $filters['domain']))
            ->when(($filters['kind'] ?? '') !== '', fn (Builder $query) => $query->where('kind', $filters['kind']))
            ->when(($filters['source'] ?? '') !== '', fn (Builder $query) => $query->where('source', $filters['source']))
            ->when(($filters['symbol'] ?? '') !== '', fn (Builder $query) => $query->whereJsonContains('related_symbols', $filters['symbol']))
            ->when(($filters['q'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('summary', 'like', $term));
            })
            ->when(($filters['from'] ?? '') !== '', fn (Builder $query) => $query->where('published_at', '>=', $filters['from']))
            ->when(($filters['to'] ?? '') !== '', fn (Builder $query) => $query->where('published_at', '<=', $filters['to'].' 23:59:59'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $latestAnalyses = $this->latestItemAnalyses($user, $items->getCollection()->pluck('id')->all());
        $locale = $user?->profile?->locale ?? 'zh';

        $items->through(fn (NewsItem $item): array => $this->itemPayload($item, $latestAnalyses[$item->id] ?? null, $locale));

        return Inertia::render('News/Index', [
            'items' => $items,
            'llmProviders' => $this->llmProviders($user),
            'latestDailySummary' => $this->latestDailySummary($user),
            'filters' => [
                'market' => $filters['market'] ?? null,
                'domain' => $filters['domain'] ?? null,
                'kind' => $filters['kind'] ?? null,
                'source' => $filters['source'] ?? null,
                'symbol' => $filters['symbol'] ?? null,
                'q' => $filters['q'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'include_irrelevant' => $includeIrrelevant,
            ],
            'facets' => [
                'markets' => $this->distinctValues('market'),
                'domains' => $this->distinctValues('domain'),
                'kinds' => $this->distinctValues('kind'),
                'sources' => $this->distinctValues('source'),
            ],
            'lastUpdatedAt' => NewsItem::max('created_at'),
            'nextUpdateTimes' => array_values((array) config('news.schedule.times', [])),
            'feedSources' => $this->feedSourcesPayload(),
        ]);
    }

    /**
     * 全部設定中的來源與其健康度，供 UI 列出連結並標示失效者。
     *
     * 來源失效在此專案是靜默發生的：實測 WSJ Markets 回 HTTP 200 且項目滿滿，
     * 但內容凍結在 547 天前，插入後即被 prune，DB 永遠 0 筆。使用者只會看到
     * 「某個媒體的新聞不見了」，卻無從得知原因。把健康度攤在 UI 上，失效才是
     * 可見的。
     *
     * @return list<array<string, mixed>>
     */
    private function feedSourcesPayload(): array
    {
        $health = FeedHealth::query()->get()->keyBy('key');

        return collect((array) config('news.feeds', []))
            ->map(function (array $feed) use ($health): array {
                $key = (string) ($feed['key'] ?? '');
                $row = $health->get($key);

                return [
                    'key' => $key,
                    'name' => (string) ($feed['name'] ?? $key),
                    'market' => (string) ($feed['market'] ?? ''),
                    // site 優先；否則由 feed URL 取主機名組出可點的首頁連結。
                    'site' => $this->feedSite($feed),
                    'healthy' => $row === null ? null : ! $row->isUnhealthy(),
                    'last_fresh_at' => $row?->last_fresh_at?->toIso8601String(),
                    'stale_runs' => (int) ($row?->consecutive_stale_runs ?? 0),
                    'last_error' => $row?->last_error,
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $feed */
    private function feedSite(array $feed): ?string
    {
        if (isset($feed['site']) && $feed['site'] !== '') {
            return (string) $feed['site'];
        }

        $host = parse_url((string) ($feed['url'] ?? ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? 'https://'.$host : null;
    }

    private function itemPayload(NewsItem $item, ?NewsAnalysis $analysis, string $locale = 'zh'): array
    {
        return [
            'id' => $item->id,
            'source' => $item->source,
            'title' => $item->title,
            'summary' => $item->summary,
            'url' => $item->url,
            'kind' => $item->kind,
            'market' => $item->market,
            'domain' => $item->domain,
            'domains' => array_values($item->domains ?? []),
            'language' => $item->language,
            'related_symbols' => array_values($item->related_symbols ?? []),
            // 傳導鏈與 related_symbols 語意不同：後者是「新聞提到這檔股票」，
            // 前者是「這個事件可能影響這檔股票」。合併會讓使用者誤以為新聞
            // 直接談到了該公司，故分開輸出。
            'transmission' => app(TransmissionMapper::class)->map(
                (string) $item->title,
                (string) $item->summary,
                (array) ($item->domains ?? []),
                $locale,
            ),
            'published_at' => $item->published_at?->toIso8601String(),
            'latest_analysis' => $analysis === null ? null : [
                'id' => $analysis->id,
                'status' => $analysis->status->value,
                // 失敗原因存在 raw_output，讓畫面能說清楚是逾時、金鑰失效還是模型名錯。
                'failure' => $analysis->raw_output['failure'] ?? null,
                'sentiment' => $analysis->sentiment,
                'impact_score' => $analysis->impact_score,
                'summary' => $analysis->summary,
                'reasoning' => $analysis->reasoning,
                'model' => $analysis->model,
                'created_at' => $analysis->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, NewsAnalysis>
     */
    private function latestItemAnalyses(User $user, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return $user->newsAnalyses()
            ->where('type', 'item')
            ->whereIn('news_item_id', $itemIds)
            ->orderByDesc('id')
            ->get()
            ->reduce(function (array $carry, NewsAnalysis $analysis): array {
                $carry[$analysis->news_item_id] ??= $analysis;

                return $carry;
            }, []);
    }

    private function latestDailySummary(User $user): ?array
    {
        $summary = $user->newsAnalyses()
            ->where('type', 'daily_summary')
            ->latest('id')
            ->first();

        if ($summary === null) {
            return null;
        }

        return [
            'id' => $summary->id,
            'type' => $summary->type,
            'status' => $summary->status->value,
            'failure' => $summary->raw_output['failure'] ?? null,
            'provider_type' => $summary->provider_type,
            'model' => $summary->model,
            'summary' => $summary->summary,
            'points' => array_values((array) ($summary->raw_output['points'] ?? [])),
            'related_symbols' => array_values($summary->related_symbols ?? []),
            'data_as_of' => $summary->data_as_of?->toIso8601String(),
            'created_at' => $summary->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function llmProviders(User $user): array
    {
        return $user->llmProviderSettings()
            ->orderByDesc('is_default')
            ->orderBy('display_name')
            ->get()
            ->map(fn (LlmProviderSetting $setting): array => [
                'id' => $setting->id,
                'display_name' => $setting->display_name,
                'provider_type' => $setting->provider_type,
                'model' => $setting->model,
                'is_default' => (bool) $setting->is_default,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    /** 篩選選項只列出相關新聞中實際存在的值，避免選了卻是空清單。 */
    private function distinctValues(string $column): array
    {
        return NewsItem::query()
            ->where('relevant', true)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
