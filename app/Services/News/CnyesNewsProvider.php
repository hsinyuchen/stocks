<?php

namespace App\Services\News;

use App\Data\NewsItemData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * 鉅亨網（cnyes）新聞。
 *
 * 鉅亨網沒有 RSS——實測 /rss/news/cat/headline、/rss/cat/headline 與舊的
 * cnyes-cdn media RSS 全部 404。但站方有公開的 JSON API，且回傳的資料比 RSS
 * 更完整：每則新聞附帶結構化的 market 陣列（{code, name, symbol}），代號是
 * 上游直接給的，不必再靠關鍵字猜測關聯個股。
 *
 * 因為不是 RSS，走獨立 provider；feed 設定以 driver 欄位指向此實作。
 */
class CnyesNewsProvider
{
    private const ENDPOINT = 'https://news.cnyes.com/api/v3/news/category';

    /** 台股代號在 API 中的市場前綴，例：TWS:2330:STOCK。 */
    private const TAIWAN_MARKET_PREFIX = 'TWS';

    public function __construct(private readonly ?int $timeoutSeconds = null) {}

    /**
     * @param  array<string, mixed>  $feed
     * @return list<NewsItemData>
     */
    public function fetch(array $feed): array
    {
        $category = (string) ($feed['category'] ?? 'headline');
        $limit = (int) ($feed['limit'] ?? 30);

        $response = Http::timeout($this->timeoutSeconds ?? (int) config('news.http_timeout', 15))
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; StockRadar/1.0)'])
            ->acceptJson()
            ->get(self::ENDPOINT.'/'.$category, ['limit' => $limit]);

        if ($response->failed()) {
            return [];
        }

        $rows = $response->json('items.data');

        if (! is_array($rows)) {
            return [];
        }

        $source = (string) ($feed['name'] ?? '鉅亨網');
        $market = $feed['market'] ?? 'TW';
        $language = (string) ($feed['language'] ?? 'zh-TW');

        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['newsId'])) {
                continue;
            }

            $items[] = new NewsItemData(
                source: $source,
                title: $this->text($row['title'] ?? ''),
                summary: $this->text($row['summary'] ?? ''),
                topic: 'macro',
                relatedSymbols: $this->symbols($row),
                publishedAt: $this->publishedAt($row['publishAt'] ?? null),
                url: 'https://news.cnyes.com/news/id/'.$row['newsId'],
                language: $language,
                market: $market,
            );
        }

        return $items;
    }

    /**
     * 上游 market 陣列的台股代號 → 本專案的 .TW 正規形式。
     *
     * symbol 形如 "TWS:2330:STOCK"。只取 TWS（台股）並補上 .TW 後綴；
     * 非台股市場（外匯、期貨、美股）此處不處理，交給既有的分類器。
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function symbols(array $row): array
    {
        $out = [];

        foreach ((array) ($row['market'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $symbol = (string) ($entry['symbol'] ?? '');
            $code = (string) ($entry['code'] ?? '');

            if ($code === '' || ! str_starts_with($symbol, self::TAIWAN_MARKET_PREFIX.':')) {
                continue;
            }

            // 只收 4 碼數字代號：上櫃/興櫃與權證代號長度不同，貿然加 .TW 會產生
            // 對不到 instrument 的假代號。
            if (preg_match('/^\d{4}$/', $code) === 1) {
                $out[] = $code.'.TW';
            }
        }

        return array_values(array_unique($out));
    }

    /** publishAt 是 Unix 秒。缺值回空字串，交由 ingestion 以 created_at 兜底。 */
    private function publishedAt(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '';
        }

        return CarbonImmutable::createFromTimestampUTC((int) $value)->toIso8601String();
    }

    private function text(mixed $value): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
