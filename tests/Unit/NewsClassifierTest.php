<?php

namespace Tests\Unit;

use App\Services\News\NewsClassifier;
use Tests\TestCase;

class NewsClassifierTest extends TestCase
{
    private function classifier(): NewsClassifier
    {
        return new NewsClassifier();
    }

    public function test_tech_keyword_maps_to_tech_domain(): void
    {
        $result = $this->classifier()->classify('台積電擴大資本支出', '半導體需求強勁');

        $this->assertSame('tech', $result['domain']);
    }

    public function test_english_tech_keyword_maps_to_tech_domain(): void
    {
        $result = $this->classifier()->classify('Nvidia chip demand surges', 'semiconductor outlook');

        $this->assertSame('tech', $result['domain']);
    }

    public function test_defense_keyword_maps_to_defense_domain(): void
    {
        $result = $this->classifier()->classify('美國對中加徵關稅', '地緣政治緊張');

        $this->assertSame('defense', $result['domain']);
    }

    public function test_finance_keyword_maps_to_finance_domain(): void
    {
        $result = $this->classifier()->classify('Fed signals rate cut', 'inflation cooling');

        $this->assertSame('finance', $result['domain']);
    }

    public function test_no_keyword_falls_back_to_other(): void
    {
        $result = $this->classifier()->classify('Local sports team wins', 'A cheerful weekend');

        $this->assertSame('other', $result['domain']);
    }

    public function test_chinese_company_name_resolves_to_symbol(): void
    {
        $result = $this->classifier()->classify('台積電擴大資本支出', '');

        $this->assertContains('2330.TW', $result['symbols']);
    }

    public function test_english_company_names_resolve_to_symbols(): void
    {
        $result = $this->classifier()->classify('Nvidia and AMD earnings', '');

        $this->assertContains('NVDA', $result['symbols']);
        $this->assertContains('AMD', $result['symbols']);
    }

    public function test_dictionary_lookup_is_case_insensitive(): void
    {
        $result = $this->classifier()->classify('TSMC posts record revenue', 'NVIDIA up too');

        $this->assertContains('2330.TW', $result['symbols']);
        $this->assertContains('NVDA', $result['symbols']);
    }

    public function test_duplicate_matches_collapse_to_one_symbol(): void
    {
        // Both the Chinese name and the English name resolve to NVDA.
        $result = $this->classifier()->classify('輝達 Nvidia 財報', 'nvidia again');

        $this->assertSame(['NVDA'], array_values(array_filter(
            $result['symbols'],
            fn (string $s): bool => $s === 'NVDA',
        )));
        $this->assertSame(1, count(array_keys($result['symbols'], 'NVDA', true)));
    }

    public function test_explicit_taiwan_ticker_with_suffix_is_matched(): void
    {
        $result = $this->classifier()->classify('2330.TW 法說會', '');

        $this->assertContains('2330.TW', $result['symbols']);
    }

    public function test_bare_four_digit_ticker_matched_against_known_tw_symbols(): void
    {
        // 2330 is a known TW symbol in the dictionary values; 9999 is not.
        $result = $this->classifier()->classify('2330 強漲 9999 不明', '');

        $this->assertContains('2330.TW', $result['symbols']);
        $this->assertNotContains('9999.TW', $result['symbols']);
        $this->assertNotContains('9999', $result['symbols']);
    }

    public function test_explicit_us_ticker_kept_only_if_in_dictionary(): void
    {
        // NVDA is a dictionary value; ZZZ is not.
        $result = $this->classifier()->classify('NVDA up, ZZZ unknown', '');

        $this->assertContains('NVDA', $result['symbols']);
        $this->assertNotContains('ZZZ', $result['symbols']);
    }

    public function test_returns_list_shape_with_domain_and_symbols_keys(): void
    {
        $result = $this->classifier()->classify('plain text', 'nothing here');

        $this->assertArrayHasKey('domain', $result);
        $this->assertArrayHasKey('symbols', $result);
        $this->assertIsString($result['domain']);
        $this->assertIsArray($result['symbols']);
        $this->assertSame(array_values($result['symbols']), $result['symbols']);
    }
}
