<?php

namespace Tests\Feature\News;

use App\Services\News\RssNewsProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RssNewsProviderExtensionTest extends TestCase
{
    private const GOOGLE_STYLE_RSS = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Google News</title>
<item>
  <title>台積電法說會亮眼</title>
  <link>https://news.google.com/rss/articles/abc123</link>
  <pubDate>Mon, 06 Jul 2026 08:00:00 GMT</pubDate>
  <description>摘要文字</description>
  <source url="https://money.udn.com">經濟日報</source>
</item>
<item>
  <title>無來源的一則</title>
  <link>https://example.com/plain</link>
  <pubDate>Mon, 06 Jul 2026 09:00:00 GMT</pubDate>
  <description>x</description>
</item>
</channel></rss>
XML;

    public function test_item_source_element_overrides_feed_name(): void
    {
        Http::fake(['news.google.com/*' => Http::response(self::GOOGLE_STYLE_RSS)]);

        $items = (new RssNewsProvider)->fetch([
            'key' => 'gnews', 'name' => 'Google News',
            'url' => 'https://news.google.com/rss/search?q=x',
            'market' => 'TW', 'language' => 'zh-TW',
        ]);

        $this->assertCount(2, $items);
        $this->assertSame('經濟日報', $items[0]->source);
        // 無 <source> 元素 fallback feed name（既有行為）
        $this->assertSame('Google News', $items[1]->source);
    }

    public function test_timeout_parameter_overrides_config(): void
    {
        Http::fake(['example.com/*' => Http::response(self::GOOGLE_STYLE_RSS)]);
        config(['news.http_timeout' => 15]);

        // 只驗證參數接受與不炸；timeout 實際值以讀碼保障（Http timeout 難以在 fake 下斷言）
        $items = (new RssNewsProvider)->fetch(
            ['key' => 'x', 'name' => 'X', 'url' => 'https://example.com/feed', 'language' => 'zh-TW'],
            timeoutSeconds: 8,
        );

        $this->assertNotEmpty($items);
    }
}
