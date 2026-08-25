<?php

namespace Tests\Unit;

use App\Data\TopicBoard;
use App\Data\TopicCandidate;
use App\Enums\RevenueUnknownReason;
use App\Enums\TopicDirection;
use App\Enums\TopicTier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TopicDataTest extends TestCase
{
    #[Test]
    public function declared_directions_map_to_the_two_cases(): void
    {
        $this->assertSame(TopicDirection::Benefits, TopicDirection::fromDeclared('positive'));
        $this->assertSame(TopicDirection::Harmed, TopicDirection::fromDeclared('negative'));

        // 兩個 enum 的字串值是前端分組與 i18n 鍵的依據，一併釘住：改掉其中任何一個
        // 都不會讓 PHP 端出錯，只會讓呈現層查不到鍵而印出原始機器值。
        $this->assertSame('benefits', TopicDirection::Benefits->value);
        $this->assertSame('harmed', TopicDirection::Harmed->value);
        $this->assertSame('core', TopicTier::Core->value);
        $this->assertSame('extended', TopicTier::Extended->value);
        $this->assertSame('periphery', TopicTier::Periphery->value);
    }

    /**
     * rate_policy 的兩個 sector 都宣告 neutral，所以「無方向」不是外圍專屬狀態，
     * 核心與延伸也會出現。config 打錯字時同樣回 null——猜一個方向等於對使用者
     * 宣稱一件系統其實不知道的事。
     */
    #[Test]
    public function a_neutral_or_unknown_declaration_has_no_direction(): void
    {
        $this->assertNull(TopicDirection::fromDeclared('neutral'));
        $this->assertNull(TopicDirection::fromDeclared(''));
        $this->assertNull(TopicDirection::fromDeclared('sideways'));
    }

    /**
     * revenueVerified 是三態。null 是「沒有序列可判」，false 是「判過、不成立」，
     * 序列化之後必須仍然分得開——前端要用它切三個不同的徽章。
     */
    #[Test]
    public function an_unknown_revenue_verification_serialises_as_null_not_false(): void
    {
        $unknown = new TopicCandidate('2330.TW', '台積電', TopicTier::Core);
        $refuted = new TopicCandidate('2317.TW', '鴻海', TopicTier::Core, revenueVerified: false);

        // 先確認鍵存在：少掉這個鍵與值為 null 在 assertNull 之下長得一樣，
        // 但對前端是兩件事（undefined 不會落進「無資料」那個分支）。
        $this->assertArrayHasKey('revenue_verified', $unknown->toArray());
        $this->assertNull($unknown->toArray()['revenue_verified']);
        $this->assertFalse($refuted->toArray()['revenue_verified']);
    }

    /**
     * 「沒有結論」有**五個**成因，序列化後必須分得開。
     *
     * 三種等得到答案（序列尚未累積、資料不足以判定、序列過舊或缺關鍵科目——
     * 最後一種要等下一次財報），一種要先有人建立標的，一種**永遠不會有答案**
     * （本框架不適用此產業）。全部壓成一句「無資料」會讓使用者一直等一個
     * 不會來的東西——規格的頭號範例題材 hormuz_oil 的核心正好就是航運股，
     * 而航運在 order_inventory.industry.not_applicable 裡。
     */
    #[Test]
    public function every_reason_for_having_no_revenue_answer_serialises_distinctly(): void
    {
        $byReason = [];

        foreach (RevenueUnknownReason::cases() as $reason) {
            $candidate = new TopicCandidate('2603.TW', '長榮', TopicTier::Core, revenueUnknownReason: $reason);

            $this->assertNull($candidate->toArray()['revenue_verified'], '有原因就代表 C1 沒有結論');

            $byReason[] = $candidate->toArray()['revenue_unknown_reason'];
        }

        $this->assertSame(
            count(RevenueUnknownReason::cases()),
            count(array_unique($byReason)),
            '任兩個成因合併成同一個序列化值，呈現層就分不開了',
        );
        $this->assertContains('not_applicable', $byReason);
        $this->assertContains('not_in_universe', $byReason);
    }

    /**
     * 有結論時**沒有原因**：`revenue_unknown_reason` 為 null 與
     * `revenue_verified` 為 null 是同一件事的兩面，兩者同時有值代表
     * 呈現層會拿到互相矛盾的兩個欄位。
     */
    #[Test]
    public function a_verified_candidate_carries_no_reason(): void
    {
        $verified = new TopicCandidate('2330.TW', '台積電', TopicTier::Core, revenueVerified: true);

        $this->assertArrayHasKey('revenue_unknown_reason', $verified->toArray());
        $this->assertNull($verified->toArray()['revenue_unknown_reason']);
    }

    #[Test]
    public function a_candidate_without_a_direction_serialises_as_null(): void
    {
        $candidate = new TopicCandidate('2882.TW', '國泰金', TopicTier::Periphery, mentionCount: 7);

        $this->assertArrayHasKey('direction', $candidate->toArray());
        $this->assertNull($candidate->toArray()['direction']);
        $this->assertSame('periphery', $candidate->toArray()['tier']);
        $this->assertSame(7, $candidate->toArray()['mention_count']);
    }

    #[Test]
    public function a_board_serialises_its_thresholds_so_the_page_can_state_them(): void
    {
        $board = new TopicBoard(
            key: 'hormuz_oil',
            label: '中東衝突／荷莫茲海峽',
            chain: ['油運咽喉受威脅'],
            candidates: [new TopicCandidate('2603.TW', '長榮', TopicTier::Core, TopicDirection::Benefits)],
            windowDays: 30,
            minMentions: 3,
        );

        $array = $board->toArray();

        // 門檻是呈現層寫出「近 30 日共同提及達 3 則」那句話的唯一來源：
        // 鍵不見了會讓那句話悄悄變成空白，而不是報錯。
        $this->assertArrayHasKey('window_days', $array);
        $this->assertArrayHasKey('min_mentions', $array);
        $this->assertSame(30, $array['window_days']);
        $this->assertSame(3, $array['min_mentions']);
        $this->assertCount(1, $array['candidates']);
        $this->assertSame('benefits', $array['candidates'][0]['direction']);
    }
}
