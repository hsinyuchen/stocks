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

    public function test_html_description_is_stripped_to_plain_text(): void
    {
        // Google News 的 <description> 是 HTML（<a href=...> 連結 + 樣式標籤），
        // 必須剝成純文字，否則前端把原始碼當內文顯示且長 URL 撐爆版面。
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Google News</title>
<item>
  <title>旺宏營收創高</title>
  <link>https://news.google.com/rss/articles/xyz</link>
  <pubDate>Mon, 06 Jul 2026 08:00:00 GMT</pubDate>
  <description>&lt;a href="https://news.google.com/rss/articles/xyz"&gt;旺宏營收創高&lt;/a&gt;&amp;nbsp;&amp;nbsp;&lt;font color="#6f6f6f"&gt;UDN&lt;/font&gt;</description>
</item>
</channel></rss>
XML;
        Http::fake(['news.google.com/*' => Http::response($rss)]);

        $items = (new RssNewsProvider)->fetch([
            'key' => 'gnews', 'name' => 'Google News',
            'url' => 'https://news.google.com/rss/search?q=x',
            'market' => 'TW', 'language' => 'zh-TW',
        ]);

        $this->assertStringNotContainsString('<', $items[0]->summary);
        $this->assertStringNotContainsString('href', $items[0]->summary);
        $this->assertStringContainsString('旺宏營收創高', $items[0]->summary);
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
