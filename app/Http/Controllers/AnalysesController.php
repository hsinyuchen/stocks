<?php

namespace App\Http\Controllers;

use App\Models\NewsAnalysis;
use App\Models\StockAnalysis;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalysesController extends Controller
{
    private const LIMIT = 60;

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'in:all,stock,news,daily'],
        ]);

        $type = $data['type'] ?? 'all';
        $user = $request->user();

        $items = $this->stockRows($user, $type)
            ->concat($this->newsRows($user, $type))
            ->sortByDesc('sort')
            ->take(self::LIMIT)
            ->map(function (array $row): array {
                unset($row['sort']);

                return $row;
            })
            ->values()
            ->all();

        return Inertia::render('Analyses/Index', [
            'items' => $items,
            'filters' => ['type' => $type],
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function stockRows(User $user, string $type): \Illuminate\Support\Collection
    {
        if (! in_array($type, ['all', 'stock'], true)) {
            return collect();
        }

        return $user->stockAnalyses()
            ->with('instrument')
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (StockAnalysis $analysis): array => [
                'kind' => 'stock',
                'id' => $analysis->id,
                'label' => $analysis->instrument?->symbol,
                'stance' => $analysis->rule_signal['stance'] ?? null,
                'model' => $analysis->model,
                'provider_type' => $analysis->provider_type,
                'created_at' => $analysis->created_at?->toIso8601String(),
                'link' => $analysis->instrument
                    ? '/stocks/search?symbol='.$analysis->instrument->symbol
                    : null,
                'sort' => $analysis->created_at,
            ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function newsRows(User $user, string $type): \Illuminate\Support\Collection
    {
        // news -> item analyses; daily -> daily_summary analyses; all -> both.
        $newsTypes = match ($type) {
            'all' => ['item', 'daily_summary'],
            'news' => ['item'],
            'daily' => ['daily_summary'],
            default => [],
        };

        if ($newsTypes === []) {
            return collect();
        }

        return $user->newsAnalyses()
            ->with('newsItem')
            ->whereIn('type', $newsTypes)
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (NewsAnalysis $analysis): array => [
                'kind' => $analysis->type === 'daily_summary' ? 'daily' : 'news',
                'id' => $analysis->id,
                'label' => $analysis->newsItem?->title ?? '今日總經摘要',
                'sentiment' => $analysis->sentiment,
                'impact' => $analysis->impact_score,
                'summary' => $analysis->summary,
                'model' => $analysis->model,
                'provider_type' => $analysis->provider_type,
                'created_at' => $analysis->created_at?->toIso8601String(),
                'link' => $analysis->newsItem?->url,
                'sort' => $analysis->created_at,
            ]);
    }
}
