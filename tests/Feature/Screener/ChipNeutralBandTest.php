<?php

namespace Tests\Feature\Screener;

use App\Data\ChipFlowData;
use App\Data\MarginFlowData;
use App\Services\Screener\Rules\ForeignBuyingStreak;
use App\Services\Screener\Rules\ForeignSellingStreak;
use App\Services\Screener\Rules\InstitutionalAccumulation;
use App\Services\Screener\Rules\RetailChasing;
use App\Services\Screener\Rules\SmartMoneyAbsorbing;
use App\Services\Screener\ScreenRule;
use Tests\TestCase;

/**
 * 選股器籌碼規則的中性帶。
 *
 * 與 SignalEngine::chipStance() 判的是同一件事：淨額的正負不足以判定方向，還要
 * 看它相對這檔的量算不算大。少了這條帶，外資淨買 1 股就會把標的推到使用者面前。
 *
 * 測資一律從 config 的門檻反推，不寫死 0.01——門檻調整時測試要跟著動，而不是
 * 靜默失效。
 */
class ChipNeutralBandTest extends TestCase
{
    private function band(): float
    {
        return (float) config('health.chip.neutral_band_volume_share');
    }

    /**
     * @param  list<int>  $foreignNets
     * @return list<ChipFlowData>
     */
    private function flows(array $foreignNets, int $trustNet = 0): array
    {
        $out = [];

        foreach ($foreignNets as $i => $net) {
            $out[] = new ChipFlowData(
                date: sprintf('2026-07-%02d', $i + 1),
                foreignNet: $net,
                trustNet: $trustNet,
                dealerNet: 0,
                totalNet: $net + $trustNet,
            );
        }

        return $out;
    }

    /**
     * @param  list<int>  $volumes
     * @return array<string, list<int|float|string>>
     */
    private function series(array $volumes): array
    {
        $count = count($volumes);

        return [
            'dates' => array_map(fn (int $i): string => sprintf('2026-07-%02d', $i + 1), range(0, $count - 1)),
            'close' => array_fill(0, $count, 100.0),
            'volume' => $volumes,
        ];
    }

    /**
     * 日期與成交量分開指定，供「籌碼日與價格日不重合」的情境使用。
     *
     * @param  list<string>  $dates
     * @param  list<int>  $volumes
     * @return array<string, list<int|float|string>>
     */
    private function seriesWithDates(array $dates, array $volumes): array
    {
        return [
            'dates' => $dates,
            'close' => array_fill(0, count($volumes), 100.0),
            'volume' => $volumes,
        ];
    }

    /** 「剛好達到門檻」的淨額：測資從 config 反推，門檻改了測試才會跟著動。 */
    private function netAtBand(int $volumeSum): int
    {
        return (int) round($this->band() * $volumeSum);
    }

    // --- 外資與投信同步買超 ---

    public function test_institutional_accumulation_ignores_negligible_net(): void
    {
        $rule = new InstitutionalAccumulation;
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([1, 1, 1, 1, 1], trustNet: 1),
        ]), '五日各買 1 股不是買超，只是雜訊。');

        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atBand), trustNet: $atBand),
        ]));
    }

    /** 兩條腿都要過門檻：只有外資顯著、投信 1 股時不算「同步買超」。 */
    public function test_institutional_accumulation_requires_both_legs_to_clear_the_band(): void
    {
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse((new InstitutionalAccumulation)->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atBand), trustNet: 1),
        ]));
    }

    // --- 連續買賣超 ---

    public function test_foreign_buying_streak_ignores_negligible_streak(): void
    {
        $rule = new ForeignBuyingStreak;
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([-500_000, 1, 1, 1, 1, 1]),
        ]), '連續五日各買 1 股不構成「連續買超」。');

        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([-500_000, ...array_fill(0, 5, $atBand)]),
        ]));
    }

    public function test_foreign_selling_streak_ignores_negligible_streak(): void
    {
        $rule = new ForeignSellingStreak;
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([500_000, -1, -1, -1, -1, -1]),
        ]));

        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([500_000, ...array_fill(0, 5, -$atBand)]),
        ]));
    }

    /**
     * 連續天數以「整段淨額」判定，不是逐日、也不是整條序列。
     *
     * 單日佔比約是五日的 1/5，逐日判會把整段顯著、但多數日子小額的連續段誤殺。
     * 這裡前四天各 1 股、第五天一次到位，逐日實作會把連續天數算成 1 而不命中。
     *
     * 連續段之前那筆反向淨額刻意等量：完整序列合計只剩 4 股（遠低於門檻），
     * 所以「把整條序列加總」的實作會不命中。用大額反向值會被 isSignificantNet()
     * 的絕對值救回來，那個變異就殺不死。
     */
    public function test_streak_is_judged_on_the_whole_segment(): void
    {
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertTrue((new ForeignBuyingStreak)->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([-$atBand, 1, 1, 1, 1, $atBand]),
        ]));
    }

    // --- 門檻邊界 ---

    /** 邊界含等於：恰好達到門檻算得上訊號，少一股才落回中性帶（與 SignalEngine 同側）。 */
    public function test_band_boundary_includes_equality(): void
    {
        $rule = new InstitutionalAccumulation;
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        // 投信腿刻意遠高於門檻，讓斷言的變數只剩外資腿。
        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([$atBand, 0, 0, 0, 0], trustNet: $atBand),
        ]), '恰好等於門檻應視為訊號。');

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([$atBand - 1, 0, 0, 0, 0], trustNet: $atBand),
        ]), '少一股就該落回中性帶。');
    }

    // --- 融資交叉規則 ---

    /**
     * @param  list<int>  $balances
     * @return list<MarginFlowData>
     */
    private function margin(array $balances): array
    {
        $out = [];

        foreach ($balances as $i => $balance) {
            $out[] = new MarginFlowData(
                date: sprintf('2026-07-%02d', $i + 1),
                marginBalance: $balance,
                marginChange: 0,
                marginLimit: $balance * 100,
                shortBalance: 0,
                shortChange: 0,
                offsetLoanAndShort: 0,
            );
        }

        return $out;
    }

    public function test_smart_money_absorbing_ignores_negligible_foreign_net(): void
    {
        $rule = new SmartMoneyAbsorbing;
        $series = $this->series(array_fill(0, 10, 1_000_000));
        $down = $this->margin([1_000_000, 975_000, 950_000, 925_000, 900_000]);
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_MARGIN => $down,
            ScreenRule::NEEDS_CHIP => $this->flows([1, 1, 1, 1, 1]),
        ]), '融資減但外資只買 1 股，不是法人在接。');

        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_MARGIN => $down,
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atBand)),
        ]));
    }

    public function test_retail_chasing_ignores_negligible_foreign_net(): void
    {
        $rule = new RetailChasing;
        $series = $this->series(array_fill(0, 10, 1_000_000));
        $up = $this->margin([1_000_000, 1_025_000, 1_050_000, 1_075_000, 1_100_000]);
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_MARGIN => $up,
            ScreenRule::NEEDS_CHIP => $this->flows([-1, -1, -1, -1, -1]),
        ]));

        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_MARGIN => $up,
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, -$atBand)),
        ]));
    }

    // --- 回放不得看到未來成交量 ---

    /**
     * MarginRule::matchesAt() 的成交量必須跟著截到該時點。
     *
     * 前五根量大、後五根量小。同一筆外資淨額在前段是雜訊、在後段是訊號；若實作
     * 取的是「當下序列的尾段」，回放到第 5 根時就會拿到後段的小量而誤命中——
     * 那是前視偏誤，回測會看到未來的成交量。
     */
    public function test_margin_replay_does_not_see_future_volume(): void
    {
        $rule = new SmartMoneyAbsorbing;
        $series = $this->series([...array_fill(0, 5, 1_000_000_000), ...array_fill(0, 5, 1_000)]);
        $atBand = $this->netAtBand(5_000);

        $context = [
            ScreenRule::NEEDS_MARGIN => $this->margin([
                1_000_000, 950_000, 900_000, 850_000, 800_000,
                750_000, 700_000, 650_000, 600_000, 550_000,
            ]),
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 10, $atBand)),
        ];

        $this->assertFalse(
            $rule->matchesAt($series, 4, $context),
            '第 5 根的分母該是前段的大量，不是後段的小量。',
        );

        $this->assertTrue(
            $rule->matchesAt($series, 9, $context),
            '最後一根看得到後段小量，同一筆淨額在那裡才是訊號。',
        );
    }

    // --- 成交量算不出來 ---

    /**
     * 算不出成交量佔比時一律不命中。
     *
     * 與 SignalEngine 的選擇相反，理由見 ChipNeutralBand::isSignificantNet() 的
     * 註解：選股器的命中會把標的推到使用者面前。
     */
    public function test_rules_do_not_match_when_volume_is_unavailable(): void
    {
        $chip = [ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, 500_000), trustNet: 500_000)];
        $chipShort = [ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, -500_000))];
        $noVolume = ['dates' => ['2026-07-01'], 'close' => [100.0]];
        $zeroVolume = $this->series(array_fill(0, 40, 0));

        foreach ([$noVolume, $zeroVolume] as $series) {
            $this->assertFalse((new InstitutionalAccumulation)->matches($series, $chip));
            $this->assertFalse((new ForeignBuyingStreak)->matches($series, $chip));
            $this->assertFalse((new ForeignSellingStreak)->matches($series, $chipShort));

            $this->assertFalse((new SmartMoneyAbsorbing)->matches($series, [
                ScreenRule::NEEDS_MARGIN => $this->margin([1_000_000, 975_000, 950_000, 925_000, 900_000]),
                ...$chip,
            ]));

            $this->assertFalse((new RetailChasing)->matches($series, [
                ScreenRule::NEEDS_MARGIN => $this->margin([1_000_000, 1_025_000, 1_050_000, 1_075_000, 1_100_000]),
                ...$chipShort,
            ]));
        }
    }

    // --- 分母依籌碼日期對齊 ---

    /**
     * 分母取的是「籌碼那幾天」的成交量，不是價格序列的尾端 N 根。
     *
     * 籌碼落後於價格是常態（實測 21 檔有 8 檔尾端日期不一致，最久差 6 個交易日）。
     * 這裡價格 10 根、籌碼只有前 5 天，且後 5 根的量大了六個數量級：兩種分母會給出
     * 相反的答案，所以這條測試能同時釘住「用籌碼日」與「不用價格尾端」。
     */
    public function test_denominator_follows_chip_dates_not_price_tail(): void
    {
        $rule = new InstitutionalAccumulation;

        // 籌碼那 5 天是小量期 → 同一筆淨額在那裡是訊號；取價格尾端會拿到大量期而漏掉。
        $quietThenLoud = $this->series([...array_fill(0, 5, 1_000), ...array_fill(0, 5, 1_000_000_000)]);
        $atQuietBand = $this->netAtBand(5_000);

        $this->assertTrue($rule->matches($quietThenLoud, [
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atQuietBand), trustNet: $atQuietBand),
        ]), '分母該是籌碼那 5 天的小量，取價格尾端的大量會漏掉這筆訊號。');

        // 反向：籌碼那 5 天是大量期 → 同一筆淨額只是雜訊；取價格尾端會拿到小量而誤命中。
        $loudThenQuiet = $this->series([...array_fill(0, 5, 1_000_000_000), ...array_fill(0, 5, 1_000)]);

        $this->assertFalse($rule->matches($loudThenQuiet, [
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atQuietBand), trustNet: $atQuietBand),
        ]), '分母該是籌碼那 5 天的大量，取價格尾端的小量會把雜訊誤判成買超。');
    }

    /** 連續段的分母也要對齊：取的是連續段那幾天，不是價格尾端同天數。 */
    public function test_streak_denominator_follows_the_segment_dates(): void
    {
        // 籌碼 6 天（07-01..07-06），連續買超在 07-02..07-06；價格 10 根，後段爆量。
        $series = $this->series([...array_fill(0, 6, 1_000), ...array_fill(0, 4, 1_000_000_000)]);
        $atBand = $this->netAtBand(5_000);

        $this->assertTrue((new ForeignBuyingStreak)->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([-1_000_000, ...array_fill(0, 5, $atBand)]),
        ]), '分母該是連續段那 5 天（07-02..07-06）的量。');
    }

    /**
     * 籌碼領先於價格：多出來的籌碼日在價格序列裡找不到，整段判定回不顯著。
     *
     * 這在正式資料上會發生——實測有 8 檔的籌碼含一個價格序列沒有的交易日
     * （2026-06-19，價格端漏抓）。落後與領先共用同一條缺日政策。
     */
    public function test_chip_leading_price_is_not_significant(): void
    {
        // 價格只到 07-08，籌碼窗口是 07-05..07-09。
        $series = $this->series(array_fill(0, 8, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse((new InstitutionalAccumulation)->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 9, $atBand), trustNet: $atBand),
        ]), '籌碼窗口的最後一天沒有對應的 K 棒，規模基準不明。');
    }

    /**
     * 採計窗口內只要有一天在價格序列找不到，就回不顯著。
     *
     * 只排除缺日會讓分母變小、佔比變大，把雜訊推向命中那一側——那正是本次要修的
     * 方向。與既有的「算不出成交量就不命中」同一側。
     */
    public function test_missing_chip_date_falls_back_to_not_significant(): void
    {
        $atBand = $this->netAtBand(5_000_000);
        $flows = [ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atBand), trustNet: $atBand)];

        // 價格序列缺 07-03，其餘四天照樣有量：只排除缺日的話分母會縮成 4/5。
        $gapped = $this->seriesWithDates(
            ['2026-07-01', '2026-07-02', '2026-07-04', '2026-07-05', '2026-07-06'],
            array_fill(0, 5, 1_000_000),
        );

        $this->assertFalse((new InstitutionalAccumulation)->matches($gapped, $flows));

        // 對照組：同樣五天、同樣的量，日期補齊就該命中。
        $complete = $this->seriesWithDates(
            ['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04', '2026-07-05'],
            array_fill(0, 5, 1_000_000),
        );

        $this->assertTrue((new InstitutionalAccumulation)->matches($complete, $flows));
    }

    /**
     * 日期格式不一致但指的是同一天，仍要對得上。
     *
     * 價格端多數走 CarbonImmutable::toDateString()，但 FinMind 那條路徑是把上游的
     * date 欄位原樣轉字串，帶時刻的形式不能排除。對不上會讓整檔靜默不命中，
     * 比誤命中更難察覺。
     */
    public function test_dates_with_a_time_component_still_align(): void
    {
        $atBand = $this->netAtBand(5_000_000);

        $withTime = $this->seriesWithDates(
            [
                '2026-07-01 00:00:00',
                '2026-07-02 00:00:00',
                '2026-07-03 00:00:00',
                '2026-07-04T00:00:00Z',
                '2026-07-05 00:00:00',
            ],
            array_fill(0, 5, 1_000_000),
        );

        $this->assertTrue((new InstitutionalAccumulation)->matches($withTime, [
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atBand), trustNet: $atBand),
        ]));
    }

    /**
     * dates 與 volume 長度不一致時整份作廢。
     *
     * 兩個陣列由 TechnicalIndicatorService::series() 逐索引配對產生，長度對不上代表
     * 它們不是同一組 K 棒的產物；硬配對只會把量對到別的日期上。與「算不出成交量」
     * 同一側處理。
     */
    public function test_mismatched_date_and_volume_lengths_are_not_significant(): void
    {
        $atBand = $this->netAtBand(5_000_000);

        // volume 比 dates 多一筆（前面多一根）。逐索引硬配對會讓每一天都對到前一天
        // 的量，分母變成 4,000,000 而不是 5,000,000，佔比被推過門檻。
        $mismatched = $this->seriesWithDates(
            ['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04', '2026-07-05'],
            [0, ...array_fill(0, 5, 1_000_000)],
        );

        $this->assertFalse((new InstitutionalAccumulation)->matches($mismatched, [
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atBand), trustNet: $atBand),
        ]));
    }

    /**
     * 回放的籌碼必須截到該時點：拿掉 MarginRule::contextAsOf() 這條會紅。
     *
     * 前段（07-01..07-05）是顯著買超、後段（07-06..07-10）只有 1 股。回放到第 5 根時
     * 正確答案是命中；少了截斷就會拿後段那五天當窗口，而那幾天的 K 棒還在未來、
     * 不在已截斷的成交量映射裡，於是變成不命中。
     *
     * 註：既有的 test_margin_replay_does_not_see_future_volume 殺不死這個變異——
     * 日期對齊之後，未來籌碼日必然查不到量，兩條路徑都回不命中。要殺它只能從
     * 「正確答案是命中」這個方向造。
     */
    public function test_margin_replay_uses_chip_visible_at_that_bar(): void
    {
        $rule = new SmartMoneyAbsorbing;
        $series = $this->series(array_fill(0, 10, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $context = [
            ScreenRule::NEEDS_MARGIN => $this->margin([
                1_000_000, 950_000, 900_000, 850_000, 800_000,
                750_000, 700_000, 650_000, 600_000, 550_000,
            ]),
            ScreenRule::NEEDS_CHIP => $this->flows([...array_fill(0, 5, $atBand), ...array_fill(0, 5, 1)]),
        ];

        $this->assertTrue(
            $rule->matchesAt($series, 4, $context),
            '第 5 根看得到的籌碼是前段的顯著買超。',
        );

        $this->assertFalse(
            $rule->matchesAt($series, 9, $context),
            '最後一根的窗口是後段那五天，各買 1 股不是法人在接。',
        );
    }
}
