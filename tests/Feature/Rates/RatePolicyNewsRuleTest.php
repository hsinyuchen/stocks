<?php

namespace Tests\Feature\Rates;

use App\Services\News\TransmissionMapper;
use Tests\TestCase;

class RatePolicyNewsRuleTest extends TestCase
{
    public function test_rate_news_still_identifies_affected_sectors(): void
    {
        $chains = (new TransmissionMapper)->map('聯準會宣布升息一碼', '市場關注後續政策路徑', ['finance']);

        $keys = array_column($chains, 'key');

        $this->assertContains('rate_policy', $keys);
    }

    public function test_rate_news_no_longer_guesses_direction(): void
    {
        // 關鍵字只能猜，殖利率是事實。方向一律交給 RatesRegimeService，
        // 否則「升息利多金融」的新聞判讀會與實際的熊平環境（殺利差）相矛盾。
        $mapper = new TransmissionMapper;

        foreach (['聯準會升息一碼', '聯準會降息一碼'] as $title) {
            $chains = $mapper->map($title, '', ['finance']);

            foreach ($chains as $chain) {
                if ($chain['key'] !== 'rate_policy') {
                    continue;
                }

                foreach ($chain['sectors'] as $sector) {
                    $this->assertSame(
                        'neutral',
                        $sector['direction'],
                        "rate_policy 的板塊方向必須中性，實際為 {$sector['direction']}",
                    );
                }
            }
        }
    }

    public function test_other_transmission_rules_keep_their_direction_cues(): void
    {
        // 只中性化利率規則，其餘事件的方向判讀不受影響。
        $chains = (new TransmissionMapper)->map('記憶體報價大漲', 'DRAM 供不應求', []);

        $memory = array_values(array_filter($chains, static fn (array $c): bool => $c['key'] === 'memory_cycle'));

        $this->assertNotSame([], $memory);
        $this->assertSame('positive', $memory[0]['sectors'][0]['direction']);
    }

    public function test_brief_indicators_no_longer_duplicate_the_ten_year_yield(): void
    {
        // ^TNX 改由利率區塊提供（含完整環境），留在背景指標會在同一份報告
        // 重複顯示同一個數字。
        $symbols = array_column((array) config('brief.indicators'), 'symbol');

        $this->assertNotContains('^TNX', $symbols);
        // 美元指數屬匯率不屬利率，必須保留。
        $this->assertContains('DX-Y.NYB', $symbols);
    }
}
