<?php

namespace Tests\Unit;

use Tests\TestCase;

class YoutubeConfigTest extends TestCase
{
    public function test_enabled_is_a_bool(): void
    {
        $this->assertIsBool(config('youtube.enabled'));
    }

    public function test_python_and_worker_are_non_empty_strings(): void
    {
        foreach (['python', 'worker'] as $key) {
            $value = config("youtube.$key");
            $this->assertIsString($value, "youtube.$key must be a string");
            $this->assertNotSame('', $value, "youtube.$key must not be empty");
        }
    }

    public function test_per_channel_limit_and_excerpt_chars_are_positive_ints(): void
    {
        foreach (['per_channel_limit', 'excerpt_chars'] as $key) {
            $value = config("youtube.$key");
            $this->assertIsInt($value, "youtube.$key must be an int");
            $this->assertGreaterThan(0, $value, "youtube.$key must be positive");
        }
    }

    public function test_languages_is_a_non_empty_list_of_strings(): void
    {
        $languages = config('youtube.languages');

        $this->assertIsArray($languages);
        $this->assertNotEmpty($languages);
        $this->assertSame(array_keys($languages), range(0, count($languages) - 1), 'languages must be a sequential list');
        foreach ($languages as $language) {
            $this->assertIsString($language);
            $this->assertNotSame('', $language);
        }
    }

    public function test_schedule_times_is_a_list_and_timezone_is_set(): void
    {
        $schedule = config('youtube.schedule');

        $this->assertIsArray($schedule);
        $this->assertArrayHasKey('times', $schedule);
        $this->assertIsArray($schedule['times']);
        $this->assertNotEmpty($schedule['times']);
        $this->assertSame(array_keys($schedule['times']), range(0, count($schedule['times']) - 1), 'schedule.times must be a list');
        foreach ($schedule['times'] as $time) {
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $time);
        }

        $this->assertArrayHasKey('timezone', $schedule);
        $this->assertIsString($schedule['timezone']);
        $this->assertNotSame('', $schedule['timezone']);
    }

    public function test_channels_is_a_non_empty_list_of_well_shaped_entries(): void
    {
        $channels = config('youtube.channels');

        $this->assertIsArray($channels);
        $this->assertNotEmpty($channels);
        $this->assertSame(array_keys($channels), range(0, count($channels) - 1), 'channels must be a sequential list');

        foreach ($channels as $channel) {
            $this->assertIsArray($channel);
            foreach (['channel_id', 'name', 'market', 'language'] as $field) {
                $this->assertArrayHasKey($field, $channel);
                $this->assertIsString($channel[$field]);
                $this->assertNotSame('', $channel[$field]);
            }
            $this->assertContains($channel['market'], ['TW', 'US', 'INTL']);
        }
    }

    public function test_channel_ids_are_unique(): void
    {
        $ids = array_column(config('youtube.channels'), 'channel_id');

        $this->assertSame(array_values(array_unique($ids)), array_values($ids), 'channel_id values must be unique');
    }
}
