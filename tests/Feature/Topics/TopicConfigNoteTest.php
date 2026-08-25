<?php

namespace Tests\Feature\Topics;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `config/topics.php` 那張實測分佈表的自洽性。
 *
 * 門檻是實測分佈不是回測，所以那張表就是這個設定值唯一的依據——它一旦與自己
 * 的結語、與 config 的其他鍵對不起來，讀者就沒有辦法判斷 `min_mentions = 3`
 * 這個數字是怎麼來的。實測抓到過的正是這種漂移：某一格更新了而「2–19 檔」
 * 那句沒跟著改。
 *
 * 這裡**不驗表格的值是否等於此刻資料庫的真實分佈**——那會讓測試壞在新聞
 * ingest 上而不是程式碼改動上。驗的是「這份文件說的話彼此對得起來」。
 */
class TopicConfigNoteTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(config_path('topics.php'));
    }

    /**
     * @return array<string, array{core: int, ge1: int, ge3: int, net: int}>
     */
    private function measuredRows(string $source): array
    {
        preg_match_all('/^\s+\*\s{3}(\w+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/m', $source, $matches, PREG_SET_ORDER);

        $rows = [];

        foreach ($matches as $row) {
            $rows[$row[1]] = [
                'core' => (int) $row[2],
                'ge1' => (int) $row[3],
                'ge3' => (int) $row[4],
                'net' => (int) $row[5],
            ];
        }

        return $rows;
    }

    #[Test]
    public function the_measured_table_covers_every_topic_and_agrees_with_itself(): void
    {
        $rows = $this->measuredRows($this->source());

        $topics = array_map('strval', array_column((array) config('news.transmission'), 'key'));

        $measured = array_keys($rows);
        sort($measured);
        sort($topics);

        $this->assertSame($topics, $measured, '實測表要涵蓋 config 的每一個題材，新增題材時得重跑一次');

        foreach ($rows as $key => $row) {
            $this->assertGreaterThanOrEqual($row['ge3'], $row['ge1'], "{$key}：>=1 不可能少於 >=3");
            $this->assertGreaterThanOrEqual($row['net'], $row['ge3'], "{$key}：扣掉核心之後不可能變多");
            $this->assertGreaterThan(0, $row['core'], "{$key}：傳導表列名的核心不可能是 0 檔");
        }
    }

    /**
     * 結語的區間必須等於表格「扣核心」那一欄的最小與最大值。
     *
     * 這正是實測抓到的漂移：ai_capex 那一格更新了，而「2–19 檔」那句沒跟著改。
     */
    #[Test]
    public function the_stated_range_matches_the_table(): void
    {
        $source = $this->source();
        $nets = array_column($this->measuredRows($source), 'net');

        $this->assertNotEmpty($nets);
        $this->assertStringContainsString(
            '（'.min($nets).'–'.max($nets).' 檔）',
            $source,
            '「仍全部有候選（N–M 檔）」的區間必須等於實測表扣核心那一欄的最小與最大值',
        );
    }

    /**
     * 上限有沒有被碰到，註解要說。
     *
     * 分佈的最大值一旦達到 `max_periphery`，那一欄就不再是「量到多少」而是
     * 「被截斷成多少」，讀者無從分辨——所以必須寫出來。
     */
    #[Test]
    public function the_note_states_whether_the_cap_is_reached(): void
    {
        $source = $this->source();
        $nets = array_column($this->measuredRows($source), 'net');
        $cap = (int) config('topics.max_periphery');

        if (max($nets) >= $cap) {
            $this->assertStringContainsString(
                '已觸 max_periphery 上限',
                $source,
                '扣核心後的最大值已達上限，那一欄量到的是截斷後的數字，註解必須寫出來',
            );

            return;
        }

        $this->assertStringContainsString(
            '未觸及 max_periphery',
            $source,
            '扣核心後的最大值未達上限，註解也要寫出來，否則讀者無從判斷這份分佈有沒有被截斷',
        );
    }

    /**
     * 同一段開頭寫「量的是多罕見，不是多有效」，結尾就不能說被砍掉的是「雜訊」
     * ——那是對被砍掉那批的**性質**宣稱，而這份取樣量不到性質。
     */
    #[Test]
    public function the_note_only_claims_what_it_measured(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString(
            '雜訊砍掉',
            $source,
            '「雜訊」是對被砍掉那批的性質宣稱，與同一段開頭的「量的是多罕見，不是多有效」自相矛盾',
        );
        $this->assertStringContainsString(
            '候選數砍掉',
            $source,
            '只能陳述量到的東西：候選數少了多少',
        );
    }
}
