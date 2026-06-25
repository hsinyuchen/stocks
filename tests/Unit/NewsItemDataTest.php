<?php

namespace Tests\Unit;

use App\Data\NewsItemData;
use PHPUnit\Framework\TestCase;

class NewsItemDataTest extends TestCase
{
    public function test_existing_named_construction_defaults_new_optional_fields(): void
    {
        $d = new NewsItemData(
            source: 's',
            title: 't',
            summary: 'm',
            topic: 'macro',
            relatedSymbols: ['NVDA'],
            publishedAt: '2026-06-25T00:00:00+00:00',
        );

        $this->assertSame('s', $d->source);
        $this->assertSame('t', $d->title);
        $this->assertSame('m', $d->summary);
        $this->assertSame('macro', $d->topic);
        $this->assertSame(['NVDA'], $d->relatedSymbols);
        $this->assertSame('2026-06-25T00:00:00+00:00', $d->publishedAt);
        $this->assertSame('', $d->url);
        $this->assertSame('zh-TW', $d->language);
        $this->assertNull($d->market);
        $this->assertSame('other', $d->domain);
    }

    public function test_new_optional_fields_can_be_provided(): void
    {
        $d2 = new NewsItemData(
            source: 's',
            title: 't',
            summary: 'm',
            topic: 'stock',
            relatedSymbols: [],
            publishedAt: 'x',
            url: 'u',
            language: 'en',
            market: 'US',
            domain: 'tech',
        );

        $this->assertSame('u', $d2->url);
        $this->assertSame('en', $d2->language);
        $this->assertSame('US', $d2->market);
        $this->assertSame('tech', $d2->domain);
    }
}
