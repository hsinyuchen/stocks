<?php

namespace Tests\Unit;

use App\Services\News\NewsClassifier;
use Tests\TestCase;

class NewsClassifierTest extends TestCase
{
    private function classifier(): NewsClassifier
    {
        return new NewsClassifier;
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

    /** 關稅與地緣屬 geopolitics，不是 defense——舊版把兩者混為一談。 */
    public function test_tariff_and_geopolitical_tension_map_to_geopolitics(): void
    {
        $result = $this->classifier()->classify('美國對中加徵關稅', '地緣政治緊張');

        $this->assertSame('geopolitics', $result['domain']);
    }

    public function test_defense_keyword_maps_to_defense_domain(): void
    {
        $result = $this->classifier()->classify('國防部公布新型飛彈採購', '軍工產業受惠');

        $this->assertSame('defense', $result['domain']);
    }

    /** 跨領域新聞必須同時標記——這類影響最大，舊版先中先贏會漏掉其一。 */
    public function test_cross_domain_item_gets_multiple_labels(): void
    {
        $result = $this->classifier()->classify('美國對半導體加徵關稅', '晶片供應鏈受衝擊');

        $this->assertContains('tech', $result['domains']);
        $this->assertContains('geopolitics', $result['domains']);
        $this->assertContains('supply_chain', $result['domains']);
    }

    /**
     * ASCII 關鍵字必須以詞邊界比對。純子字串會安靜地誤判：
     * war→software、nato→senator、coup→couple、dram→drama、port→sports。
     */
    public function test_ascii_keywords_match_on_word_boundaries_only(): void
    {
        $classifier = $this->classifier();

        $this->assertSame('other', $classifier->classify('A couple of drama series', '')['domain']);
        $this->assertNotContains('supply_chain', $classifier->classify('Local sports results', '')['domains']);

        // 真的出現該詞時仍要命中。
        $this->assertContains('geopolitics', $classifier->classify('NATO expands its role', '')['domains']);
    }

    /** 中文沒有詞邊界，必須維持子字串比對。 */
    public function test_cjk_keywords_still_match_as_substrings(): void
    {
        $result = $this->classifier()->classify('台股下殺記憶體也逃難', '南亞科跌停');

        $this->assertContains('tech', $result['domains']);
        $this->assertContains('market', $result['domains']);
    }

    /** 有領域或個股訊號時一律保留，即使命中排除字。 */
    public function test_item_with_domain_signal_stays_relevant_even_if_noise_keyword_present(): void
    {
        $result = $this->classifier()->classify('台積電法說會後美食街人潮回流', '半導體展望樂觀');

        $this->assertTrue($result['relevant']);
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
        $this->assertSame([], $result['domains']);

        // 無領域、無個股、且命中排除字 → 判定與投資無關。
        $this->assertFalse($result['relevant']);
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
