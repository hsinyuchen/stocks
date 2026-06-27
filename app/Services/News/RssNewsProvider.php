<?php

namespace App\Services\News;

use App\Data\NewsItemData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * Fetches and parses a single feed URL (RSS or Atom) into NewsItemData[].
 *
 * Pure fetch + parse: no DB writes and no classification. The ingestion
 * service runs the classifier and persists. Testable with Http::fake().
 */
class RssNewsProvider
{
    /**
     * Fetch one feed (a config('news.feeds') entry) and return its items.
     *
     * On HTTP error the underlying RequestException is thrown so the
     * ingestion service can isolate the failing feed. Empty or malformed
     * XML yields an empty list.
     *
     * @param  array{key?: string, name?: string, url?: string, market?: string, language?: string}  $feed
     * @return list<NewsItemData>
     */
    public function fetch(array $feed): array
    {
        $url = (string) ($feed['url'] ?? '');

        $body = Http::timeout((int) config('news.http_timeout', 15))
            ->get($url)
            ->throw()
            ->body();

        $xml = $this->parseXml($body);

        if ($xml === null) {
            return [];
        }

        $source = (string) ($feed['name'] ?? ($feed['key'] ?? ''));
        $market = isset($feed['market']) ? (string) $feed['market'] : null;
        $language = (string) ($feed['language'] ?? 'zh-TW');

        // RSS: channel/item. Atom: feed/entry.
        if (isset($xml->channel)) {
            return $this->mapNodes($xml->channel->item, $source, $market, $language, atom: false);
        }

        return $this->mapNodes($xml->entry, $source, $market, $language, atom: true);
    }

    /**
     * Parse the body, tolerating empty/garbage input (returns null).
     */
    private function parseXml(string $body): ?SimpleXMLElement
    {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml === false ? null : $xml;
    }

    /**
     * @return list<NewsItemData>
     */
    private function mapNodes(?SimpleXMLElement $nodes, string $source, ?string $market, string $language, bool $atom): array
    {
        if ($nodes === null) {
            return [];
        }

        $items = [];

        foreach ($nodes as $node) {
            $items[] = new NewsItemData(
                source: $source,
                title: $this->text($node->title),
                summary: $atom ? $this->text($node->summary) : $this->text($node->description),
                topic: 'macro',
                relatedSymbols: [],
                publishedAt: $atom
                    ? $this->date($node->updated)
                    : $this->date($node->pubDate),
                url: $atom ? $this->atomLink($node) : $this->text($node->link),
                language: $language,
                market: $market,
            );
        }

        return $items;
    }

    private function text(?SimpleXMLElement $node): string
    {
        return $node === null ? '' : trim((string) $node);
    }

    /**
     * Atom links carry the URL in the href attribute.
     */
    private function atomLink(SimpleXMLElement $node): string
    {
        if (! isset($node->link)) {
            return '';
        }

        $href = $node->link['href'] ?? null;

        return $href === null ? '' : trim((string) $href);
    }

    /**
     * Normalize a date string to ISO-8601; empty string when missing/unparsable.
     */
    private function date(?SimpleXMLElement $node): string
    {
        $raw = $this->text($node);

        if ($raw === '') {
            return '';
        }

        try {
            return Carbon::parse($raw)->toIso8601String();
        } catch (\Throwable) {
            return $raw;
        }
    }
}
