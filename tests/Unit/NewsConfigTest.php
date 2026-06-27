<?php

namespace Tests\Unit;

use Tests\TestCase;

class NewsConfigTest extends TestCase
{
    public function test_feeds_is_a_non_empty_list_of_well_shaped_entries(): void
    {
        $feeds = config('news.feeds');

        $this->assertIsArray($feeds);
        $this->assertNotEmpty($feeds);
        $this->assertSame(array_keys($feeds), range(0, count($feeds) - 1), 'feeds must be a sequential list');

        foreach ($feeds as $feed) {
            $this->assertIsArray($feed);
            foreach (['key', 'name', 'url', 'market', 'language'] as $field) {
                $this->assertArrayHasKey($field, $feed);
                $this->assertIsString($feed[$field]);
                $this->assertNotSame('', $feed[$field]);
            }
            $this->assertContains($feed['market'], ['TW', 'US', 'INTL']);
        }
    }

    public function test_feed_keys_are_unique(): void
    {
        $keys = array_column(config('news.feeds'), 'key');

        $this->assertSame(array_values(array_unique($keys)), array_values($keys), 'feed keys must be unique');
    }

    public function test_domains_define_keyword_lists_for_tech_defense_finance(): void
    {
        $domains = config('news.domains');

        $this->assertIsArray($domains);
        foreach (['tech', 'defense', 'finance'] as $domain) {
            $this->assertArrayHasKey($domain, $domains);
            $this->assertIsArray($domains[$domain]);
            $this->assertNotEmpty($domains[$domain]);
            foreach ($domains[$domain] as $keyword) {
                $this->assertIsString($keyword);
                $this->assertNotSame('', $keyword);
            }
        }
    }

    public function test_symbols_maps_names_to_symbols(): void
    {
        $symbols = config('news.symbols');

        $this->assertIsArray($symbols);
        $this->assertNotEmpty($symbols);
        foreach ($symbols as $name => $symbol) {
            $this->assertIsString($name);
            $this->assertNotSame('', $name);
            $this->assertIsString($symbol);
            $this->assertNotSame('', $symbol);
        }

        $this->assertSame('2330.TW', $symbols['台積電'] ?? null);
        $this->assertSame('NVDA', $symbols['nvidia'] ?? null);
    }

    public function test_schedule_times_is_a_list_and_timezone_is_set(): void
    {
        $schedule = config('news.schedule');

        $this->assertIsArray($schedule);
        $this->assertArrayHasKey('times', $schedule);
        $this->assertIsArray($schedule['times']);
        $this->assertNotEmpty($schedule['times']);
        $this->assertSame(array_keys($schedule['times']), range(0, count($schedule['times']) - 1), 'schedule.times must be a list');
        foreach ($schedule['times'] as $time) {
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $time);
        }

        $this->assertArrayHasKey('timezone', $schedule);
        $this->assertSame('Asia/Taipei', $schedule['timezone']);
    }

    public function test_retention_days_is_an_int(): void
    {
        $this->assertIsInt(config('news.retention_days'));
        $this->assertGreaterThan(0, config('news.retention_days'));
    }
}
