<?php

namespace Tests\Feature\News;

use App\Data\NewsItemData;
use App\Services\News\RssNewsProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RssNewsProviderTest extends TestCase
{
    private function feed(array $overrides = []): array
    {
        return array_merge([
            'key' => 'cnbc',
            'name' => 'CNBC',
            'url' => 'https://example.test/feed.xml',
            'market' => 'US',
            'language' => 'en',
        ], $overrides);
    }

    public function test_parses_rss_channel_items(): void
    {
        $rss = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <title>CNBC Feed</title>
            <item>
              <title>Nvidia hits record high</title>
              <link>https://example.test/articles/1</link>
              <description>Chip demand surges on AI buildout.</description>
              <pubDate>Wed, 25 Jun 2026 12:00:00 +0000</pubDate>
            </item>
            <item>
              <title>Markets rally into the close</title>
              <link>https://example.test/articles/2</link>
              <description>Stocks climb broadly.</description>
              <pubDate>Wed, 25 Jun 2026 18:30:00 +0000</pubDate>
            </item>
          </channel>
        </rss>
        XML;

        Http::fake([
            'example.test/*' => Http::response($rss, 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $items = (new RssNewsProvider)->fetch($this->feed());

        $this->assertCount(2, $items);
        $this->assertContainsOnlyInstancesOf(NewsItemData::class, $items);

        $first = $items[0];
        $this->assertSame('Nvidia hits record high', $first->title);
        $this->assertSame('https://example.test/articles/1', $first->url);
        $this->assertSame('Chip demand surges on AI buildout.', $first->summary);
        $this->assertSame('CNBC', $first->source);
        $this->assertSame('US', $first->market);
        $this->assertSame('en', $first->language);
        $this->assertSame('macro', $first->topic);
        $this->assertSame('other', $first->domain);
        $this->assertSame([], $first->relatedSymbols);
        $this->assertNotSame('', $first->publishedAt);
    }

    public function test_parses_atom_feed_entries(): void
    {
        $atom = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
          <title>Atom Feed</title>
          <entry>
            <title>Fed signals rate cut</title>
            <link href="https://example.test/atom/1"/>
            <summary>Inflation is cooling.</summary>
            <updated>2026-06-25T08:00:00Z</updated>
          </entry>
          <entry>
            <title>Bond yields slip</title>
            <link href="https://example.test/atom/2"/>
            <summary>Treasuries gain.</summary>
            <updated>2026-06-25T09:00:00Z</updated>
          </entry>
        </feed>
        XML;

        Http::fake([
            'example.test/*' => Http::response($atom, 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $items = (new RssNewsProvider)->fetch($this->feed());

        $this->assertCount(2, $items);

        $first = $items[0];
        $this->assertSame('Fed signals rate cut', $first->title);
        $this->assertSame('https://example.test/atom/1', $first->url);
        $this->assertSame('Inflation is cooling.', $first->summary);
        $this->assertSame('CNBC', $first->source);
        $this->assertNotSame('', $first->publishedAt);
    }

    public function test_http_error_throws(): void
    {
        Http::fake([
            'example.test/*' => Http::response('boom', 500),
        ]);

        $this->expectException(RequestException::class);

        (new RssNewsProvider)->fetch($this->feed());
    }

    public function test_empty_body_returns_empty_array(): void
    {
        Http::fake([
            'example.test/*' => Http::response('', 200),
        ]);

        $this->assertSame([], (new RssNewsProvider)->fetch($this->feed()));
    }

    public function test_garbage_xml_returns_empty_array(): void
    {
        Http::fake([
            'example.test/*' => Http::response('<<not xml at all >>>', 200),
        ]);

        $this->assertSame([], (new RssNewsProvider)->fetch($this->feed()));
    }

    public function test_missing_fields_are_tolerated(): void
    {
        $rss = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <item>
              <title>Only a title here</title>
            </item>
          </channel>
        </rss>
        XML;

        Http::fake([
            'example.test/*' => Http::response($rss, 200),
        ]);

        $items = (new RssNewsProvider)->fetch($this->feed());

        $this->assertCount(1, $items);
        $this->assertSame('Only a title here', $items[0]->title);
        $this->assertSame('', $items[0]->url);
        $this->assertSame('', $items[0]->summary);
    }
}
