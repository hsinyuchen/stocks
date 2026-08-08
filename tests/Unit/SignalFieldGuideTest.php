<?php

namespace Tests\Unit;

use App\Services\Analysis\SignalFieldGuide;
use PHPUnit\Framework\TestCase;

/**
 * 欄位指南抽出後的回歸鎖。
 *
 * StockAnalysisPromptGuideTest 驗的是「分析 prompt 裡有這些句子」，本檔驗的是
 * 「這些句子確實來自 SignalFieldGuide」。兩者合起來才能在有人只改其中一邊時
 * 立刻紅燈——個股問答與個股分析共用這份指南，分岔就代表其中一邊會重新長出
 * 另一邊已經修掉的幻覺。
 */
class SignalFieldGuideTest extends TestCase
{
    /** @return array<string, mixed> */
    private function signalWithChipAndMargin(): array
    {
        return [
            'stance' => 'bearish',
            'score' => -3,
            'reasons' => ['技術面偏弱。'],
            'chip' => [
                'stance' => 'accumulating',
                'days' => 5,
                'foreign_net' => 4_975_729,
                'foreign_streak' => 3,
                'as_of' => '2026-07-27',
            ],
            'alignment' => 'diverge',
            'margin' => [
                'stance' => 'leveraging',
                'days' => 5,
                'balance' => 12_000_000,
                'crossover' => 'retail_chasing',
                'as_of' => '2026-07-27',
            ],
        ];
    }

    public function test_base_lines_are_always_present(): void
    {
        $guide = (new SignalFieldGuide)->forRuleSignal(['stance' => 'watch', 'score' => 1]);

        $this->assertStringContainsString('null 代表指標仍在暖身期', $guide);
        $this->assertStringContainsString('高度共線', $guide);
        $this->assertStringContainsString('不得臆測未提供的資訊', $guide);
    }

    /** 沒有籌碼時必須明確禁止臆測，且不得出現任何籌碼欄位名稱去提示模型「應該要有」。 */
    public function test_guide_omits_chip_lines_when_chip_data_is_absent(): void
    {
        $guide = (new SignalFieldGuide)->forRuleSignal(['stance' => 'watch', 'score' => 1]);

        $this->assertStringContainsString('本次未提供籌碼資料', $guide);
        $this->assertStringContainsString('不得臆測三大法人買賣超', $guide);
        $this->assertStringNotContainsString('chip.foreign_net', $guide);
    }

    public function test_guide_omits_margin_lines_when_margin_data_is_absent(): void
    {
        $guide = (new SignalFieldGuide)->forRuleSignal(['stance' => 'watch', 'score' => 1]);

        $this->assertStringContainsString('本次未提供融資融券資料', $guide);
        $this->assertStringNotContainsString('margin.crossover', $guide);
    }

    /** 籌碼抓失敗但融資成功時，融資段落仍要輸出——兩者各自 best-effort。 */
    public function test_margin_guide_is_emitted_even_without_chip_data(): void
    {
        $guide = (new SignalFieldGuide)->forRuleSignal([
            'stance' => 'watch',
            'margin' => ['stance' => 'neutral', 'crossover' => 'none'],
        ]);

        $this->assertStringContainsString('本次未提供籌碼資料', $guide);
        $this->assertStringContainsString('margin.crossover', $guide);
    }

    public function test_guide_explains_chip_and_margin_when_both_are_present(): void
    {
        $guide = (new SignalFieldGuide)->forRuleSignal($this->signalWithChipAndMargin());

        $this->assertStringContainsString('單位是「股」（1 張 = 1000 股）', $guide);
        $this->assertStringContainsString('chip.foreign_net 含外資自營商', $guide);
        $this->assertStringContainsString('confirm 為同向、diverge 為背離', $guide);
        $this->assertStringContainsString('背離比同向更有資訊量', $guide);
        $this->assertStringContainsString('融資增加本身不等於看空', $guide);
        $this->assertStringContainsString('retail_chasing', $guide);
    }
}
