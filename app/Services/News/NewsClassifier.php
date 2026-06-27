<?php

namespace App\Services\News;

/**
 * Rule-based classifier: assigns a domain and related stock symbols to a news
 * item using keyword lists and a company-name dictionary from config/news.php.
 *
 * Pure and side-effect free; no DB, no HTTP.
 */
class NewsClassifier
{
    /**
     * @return array{domain: string, symbols: list<string>}
     */
    public function classify(string $title, string $summary): array
    {
        $haystack = strtolower(trim($title.' '.$summary));

        return [
            'domain' => $this->domain($haystack),
            'symbols' => $this->symbols($title.' '.$summary, $haystack),
        ];
    }

    /**
     * First domain whose keyword appears in the haystack wins; else "other".
     */
    private function domain(string $haystack): string
    {
        /** @var array<string, list<string>> $domains */
        $domains = (array) config('news.domains', []);

        foreach ($domains as $domain => $keywords) {
            foreach ((array) $keywords as $keyword) {
                if ($keyword !== '' && str_contains($haystack, strtolower((string) $keyword))) {
                    return (string) $domain;
                }
            }
        }

        return 'other';
    }

    /**
     * Explicit ticker regex (TW + US) filtered against known symbols, plus
     * company-name dictionary matches. De-duplicated, canonical form preserved.
     *
     * @return list<string>
     */
    private function symbols(string $original, string $haystack): array
    {
        /** @var array<string, string> $dictionary */
        $dictionary = (array) config('news.symbols', []);

        $canonical = array_values(array_unique(array_map('strval', array_values($dictionary))));
        $knownTwBare = $this->knownTaiwanBareCodes($canonical);

        $symbols = [];

        // 1. Company-name dictionary matches (case-insensitive).
        foreach ($dictionary as $name => $symbol) {
            $needle = strtolower((string) $name);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                $symbols[] = (string) $symbol;
            }
        }

        // 2. Explicit Taiwan tickers: \b\d{4}(\.TWO?)? .
        if (preg_match_all('/\b(\d{4})(\.TWO?)?\b/i', $original, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $code = $match[1];
                $suffix = isset($match[2]) ? strtoupper($match[2]) : '';

                if ($suffix !== '') {
                    // Suffix present -> normalize to canonical .TW/.TWO form.
                    $symbols[] = $code.$suffix;
                } elseif (isset($knownTwBare[$code])) {
                    // Bare 4-digit kept only if it is a known TW symbol.
                    $symbols[] = $knownTwBare[$code];
                }
            }
        }

        // 3. Explicit US tickers: \b[A-Z]{1,5}\b kept only if in the dictionary.
        if (preg_match_all('/\b[A-Z]{1,5}\b/', $original, $usMatches)) {
            $canonicalIndex = array_flip($canonical);
            foreach ($usMatches[0] as $candidate) {
                if (isset($canonicalIndex[$candidate])) {
                    $symbols[] = $candidate;
                }
            }
        }

        return array_values(array_unique($symbols));
    }

    /**
     * Map of bare 4-digit code => canonical TW symbol, from dictionary values
     * that look like Taiwan tickers (e.g. "2330.TW" => ["2330" => "2330.TW"]).
     *
     * @param  list<string>  $canonical
     * @return array<string, string>
     */
    private function knownTaiwanBareCodes(array $canonical): array
    {
        $map = [];

        foreach ($canonical as $symbol) {
            if (preg_match('/^(\d{4})\.TWO?$/i', $symbol, $m)) {
                $map[$m[1]] = $symbol;
            }
        }

        return $map;
    }
}
