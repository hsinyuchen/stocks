<?php

namespace App\Services;

use App\Contracts\LlmProvider;
use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use Carbon\CarbonImmutable;

class StockAnalysisService
{
    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly NewsProvider $news,
        private readonly LlmProvider $llm,
        private readonly TechnicalIndicatorService $indicators,
        private readonly SignalEngine $signals,
    ) {}

    public function analyze(string $symbol, string $model): array
    {
        $quote = $this->marketData->quote($symbol);
        $prices = $this->marketData->dailyPrices($symbol, 80);
        $technicalSnapshot = $this->indicators->calculate($prices);
        $ruleSignal = $this->signals->evaluate($technicalSnapshot);
        $news = $this->news->relatedNews($symbol, 5);
        $prompt = $this->buildPrompt($symbol, $quote, $technicalSnapshot, $ruleSignal, $news);
        $llm = $this->llm->complete($model, $prompt);

        return [
            'symbol' => $symbol,
            'quote' => $quote,
            'technical_snapshot' => $technicalSnapshot,
            'rule_signal' => $ruleSignal,
            'news' => $news,
            'llm' => [
                'provider' => $llm->provider,
                'model' => $llm->model,
                'content' => $llm->content,
                'metadata' => $llm->metadata,
            ],
            'data_as_of' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    private function buildPrompt(
        string $symbol,
        object $quote,
        array $technicalSnapshot,
        array $ruleSignal,
        array $news,
    ): string {
        $technicalSnapshotJson = json_encode($technicalSnapshot, JSON_UNESCAPED_UNICODE);
        $ruleSignalJson = json_encode($ruleSignal, JSON_UNESCAPED_UNICODE);
        $newsTitles = implode("\n", array_map(
            fn ($item) => "- {$item->title}: {$item->summary}",
            $news,
        ));

        return <<<PROMPT
You are a financial analysis assistant. Provide reference analysis only, not guaranteed investment advice.

Symbol: {$symbol}
Price: {$quote->price}
Technical snapshot: {$technicalSnapshotJson}
Rule signal: {$ruleSignalJson}
Related news:
{$newsTitles}

Return stance, reference action, reasons, risks, and invalidating conditions.
PROMPT;
    }
}
