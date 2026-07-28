<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Services\News\TransmissionMapper;
use Illuminate\Console\Command;

/**
 * 找出「有投資意義但沒有任何傳導鏈覆蓋」的新聞，並統計其中的高頻詞。
 *
 * 補傳導鏈規則若憑印象進行，容易補到罕見情境而漏掉每天都出現的主題。這個
 * 指令把它變成可量測的循環：跑一次 → 看高頻未覆蓋詞 → 針對前幾名補規則 →
 * 再跑一次看覆蓋率有沒有真的上升。
 *
 * 只統計詞頻，不自動產生規則——受影響的板塊與方向需要人判斷，不是詞頻能決定的。
 */
class TransmissionGapsCommand extends Command
{
    protected $signature = 'news:transmission-gaps
        {--domain= : 只看特定領域（tech / energy / geopolitics ...）}
        {--terms=25 : 顯示前幾名高頻詞}
        {--samples=8 : 顯示幾則未覆蓋的標題範例}';

    protected $description = '統計未被傳導鏈覆蓋的新聞與其中的高頻詞，作為補規則的依據';

    /**
     * 常見但不具主題辨識度的詞。中文為單字詞，英文為功能詞與新聞通用語。
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'has', 'have', 'are', 'was', 'will',
        'its', 'his', 'her', 'they', 'their', 'you', 'your', 'not', 'but', 'can', 'how', 'why',
        'what', 'who', 'when', 'where', 'more', 'most', 'new', 'says', 'said', 'after', 'over',
        'into', 'out', 'about', 'than', 'now', 'top', 'best', 'first', 'here', 'could', 'would',
        'may', 'one', 'two', 'get', 'got', 'make', 'made', 'just', 'all', 'his',
    ];

    public function handle(TransmissionMapper $mapper): int
    {
        $domainFilter = (string) $this->option('domain');
        $uncovered = [];

        NewsItem::query()
            ->where('relevant', true)
            ->when($domainFilter !== '', fn ($q) => $q->where('domain', $domainFilter))
            // id 必須包含在 select 內，chunkById 需要它來翻頁。
            ->select(['id', 'title', 'summary', 'domain', 'domains'])
            ->chunkById(500, function ($items) use ($mapper, &$uncovered): void {
                foreach ($items as $item) {
                    $chains = $mapper->map(
                        (string) $item->title,
                        (string) $item->summary,
                        (array) ($item->domains ?? []),
                    );

                    if ($chains === []) {
                        $uncovered[] = $item;
                    }
                }
            }, 'id');

        $total = NewsItem::query()
            ->where('relevant', true)
            ->when($domainFilter !== '', fn ($q) => $q->where('domain', $domainFilter))
            ->count();

        if ($total === 0) {
            $this->warn('沒有符合條件的新聞。');

            return self::SUCCESS;
        }

        $covered = $total - count($uncovered);

        $this->info(sprintf(
            '傳導鏈覆蓋率：%d / %d（%.1f%%）%s',
            $covered,
            $total,
            $covered / $total * 100,
            $domainFilter !== '' ? "　領域＝{$domainFilter}" : '',
        ));

        if ($uncovered === []) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('未覆蓋新聞中的高頻詞（補規則時優先考慮排名靠前者）：');

        foreach ($this->topTerms($uncovered, (int) $this->option('terms')) as $term => $count) {
            $this->line(sprintf('  %-24s %4d', $term, $count));
        }

        $this->newLine();
        $this->line('未覆蓋標題範例：');

        foreach (array_slice($uncovered, 0, (int) $this->option('samples')) as $item) {
            $this->line('  ['.$item->domain.'] '.mb_substr((string) $item->title, 0, 66));
        }

        return self::SUCCESS;
    }

    /**
     * 未覆蓋標題的詞頻。
     *
     * 英文以非字母數字切分並過濾停用詞；中文取 2-gram（無斷詞器，與
     * NewsEventClusterer 同樣的取捨）。只統計標題，不含摘要——標題的用詞
     * 密度高，摘要會把常見句式的權重灌上來。
     *
     * @param  list<NewsItem>  $items
     * @return array<string, int>
     */
    private function topTerms(array $items, int $limit): array
    {
        $counts = [];

        foreach ($items as $item) {
            $seen = [];

            foreach ($this->tokenize((string) $item->title) as $token) {
                // 每則標題內同一個詞只計一次，避免單篇重複用詞灌高排名。
                if (isset($seen[$token])) {
                    continue;
                }

                $seen[$token] = true;
                $counts[$token] = ($counts[$token] ?? 0) + 1;
            }
        }

        arsort($counts);

        return array_slice($counts, 0, max($limit, 1), true);
    }

    /** @return list<string> */
    private function tokenize(string $title): array
    {
        $title = mb_strtolower(trim($title));
        $tokens = [];

        // 先切出詞，再把中英混合的詞（例如「Yahoo股市」）拆成純 ASCII 段與純
        // CJK 段。不拆的話整段會落進 CJK 分支做 2-gram，產出 ya / ah / ho / oo
        // 這類無意義的英文 bigram 汙染排名。
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            preg_match_all('/[\x00-\x7F]+|[^\x00-\x7F]+/u', $chunk, $runs);

            foreach ($runs[0] as $run) {
                if (preg_match('/^[\x00-\x7F]+$/', $run) === 1) {
                    // 三字元以下的英文詞辨識度太低，數字也不具主題性。
                    if (mb_strlen($run) > 3 && ! in_array($run, self::STOP_WORDS, true) && ! is_numeric($run)) {
                        $tokens[] = $run;
                    }

                    continue;
                }

                $length = mb_strlen($run);

                for ($i = 0; $i < $length - 1; $i++) {
                    $tokens[] = mb_substr($run, $i, 2);
                }
            }
        }

        return $tokens;
    }
}
