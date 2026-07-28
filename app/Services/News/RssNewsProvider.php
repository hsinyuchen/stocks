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
     * @param  int|null  $timeoutSeconds  per-call HTTP timeout override; null 時沿用 config('news.http_timeout')
     * @return list<NewsItemData>
     */
    public function fetch(array $feed, ?int $timeoutSeconds = null): array
    {
        $url = (string) ($feed['url'] ?? '');

        $body = Http::timeout($timeoutSeconds ?? (int) config('news.http_timeout', 15))
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
                // Google News RSS 每則 item 帶 <source> 子元素標示原始媒體名，
                // 較 feed 層級名稱精確；非空時優先採用，缺漏才 fallback feed name。
                // Atom entry 無此欄位，故僅 RSS 路徑解析。
                source: ! $atom && ($itemSource = $this->text($node->source)) !== '' ? $itemSource : $source,
                title: $this->plainText($node->title),
                summary: $atom ? $this->atomSummary($node) : $this->plainText($node->description),
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

    /**
     * Atom entry 的摘要，缺 <summary> 時退回 Media RSS 的 media:group/media:description。
     *
     * YouTube 頻道 feed 是 Atom，但影片描述放在 media:description，<summary> 不存在。
     * 沒有這段退路的話，所有 YouTube 影片入庫時摘要都是空的，分類器只剩標題可用。
     */
    private function atomSummary(SimpleXMLElement $node): string
    {
        $summary = $this->plainText($node->summary);

        if ($summary !== '') {
            return $summary;
        }

        $media = $node->children('http://search.yahoo.com/mrss/');

        if (isset($media->group->description)) {
            return $this->plainText($media->group->description);
        }

        if (isset($media->description)) {
            return $this->plainText($media->description);
        }

        return '';
    }

    private function text(?SimpleXMLElement $node): string
    {
        return $node === null ? '' : trim((string) $node);
    }

    /**
     * 剝成純文字：部分 feed（尤其 Google News）的 title/description 內含
     * HTML（<a>、<font>）與實體。原樣入庫會讓前端把原始碼當內文顯示，
     * 長 URL 還會撐爆版面；分類器吃到 markup 也會失準。
     */
    private function plainText(?SimpleXMLElement $node): string
    {
        $raw = $this->text($node);

        if ($raw === '') {
            return '';
        }

        $decoded = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 摺疊空白（含 &nbsp; 解碼後的不斷行空格 \x{00A0}）
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $decoded) ?? '');
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
            // 正規化為 UTC：來源 pubDate 可能帶任意 offset（台灣 feed 常用
            // +0800）。若保留原 offset 表示法，Eloquent datetime cast 會把
            // 牆鐘時間直接當 UTC 存（丟棄 offset），前端再轉台北時多加 8 小時，
            // 造成新聞時間跳到未來。先轉 UTC，牆鐘即為真 UTC，存取皆正確。
            return Carbon::parse($raw)->utc()->toIso8601String();
        } catch (\Throwable) {
            return $raw;
        }
    }
}
