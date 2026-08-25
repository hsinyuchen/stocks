<?php

namespace App\Services\Topics;

use App\Enums\TopicDirection;
use App\Models\NewsItem;
use App\Services\News\TransmissionMapper;
use App\Services\Social\NewsHeatCalculator;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * 題材 × 標的的新聞共同提及計數。唯一碰 `news_items` 的地方。
 *
 * 「共同提及」＝一則新聞同時（a）觸發某個傳導鏈規則、（b）`related_symbols`
 * 含該標的。這**不代表因果關聯**，只代表兩者出現在同一則新聞裡——呈現層的
 * 必要說明必須寫明這件事。
 *
 * 只取 {@see TransmissionMapper::map()} 回傳的 `key`。該方法的
 * `sectors[].direction` 已被單則新聞的極性翻轉過，而題材頁面對的是整個視窗，
 * 用它就得把數百則新聞的極性聚合成一個結論——那是一個沒有依據的新推論。
 * 方向一律取 config 的宣告值，見 {@see TopicDirection}。
 *
 * 效能作法照 {@see NewsHeatCalculator}：單次查詢、
 * `toBase()` 不 hydrate、整份記憶化。階段 4 的 I2 實測，5302 列的成本幾乎
 * 全在「把列變成 model」而不是查詢本身（查詢約 7ms、整體 275ms）。
 *
 * 綁定為 scoped 而非 singleton：同一次請求內 Task 3 會對同一題材問很多次，
 * 該共用；但常駐 queue worker 不該跨日沿用同一份快照。
 */
class TopicNewsMentions
{
    /**
     * 整個視窗的計數快照。鍵是基準時刻的**日期字串**，不是完整時刻——
     * 同一天的不同時刻刻意共用同一份快照，理由見 {@see self::countsByTopic()}。
     *
     * @var array<string, array<string, array<string, int>>>
     */
    private array $memo = [];

    public function __construct(private readonly TransmissionMapper $mapper = new TransmissionMapper) {}

    /**
     * @return array<string, int> symbol => 提及則數，依次數遞減
     */
    public function forTopic(string $topicKey, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        return $this->countsByTopic($now)[$topicKey] ?? [];
    }

    /**
     * 整個視窗的「題材 => (symbol => 則數)」。
     *
     * 記憶化的鍵是 `$now` 的**日期字串**而不是完整時刻：呼叫端逐題材呼叫時
     * `$now` 可能各自是 `CarbonImmutable::now()`、微秒都不同，鍵到完整時刻
     * 會讓每一次呼叫都重跑整個視窗的掃描。日期層級的粒度正好對應這份資料的
     * 更新頻率（新聞 ingest 是排程的）。
     *
     * @return array<string, array<string, int>>
     */
    private function countsByTopic(CarbonImmutable $now): array
    {
        $key = $now->toDateString();

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $windowDays = $this->requireInt('window_days');

        // 下界取基準日零點再往前 window_days 天，且**含**該時刻本身：視窗以
        // 日曆日為單位（與 NewsHeatCalculator 一致），使用者看到的「近 30 日」
        // 指的是 30 個完整日曆日，不是「30×24 小時前的此刻」。
        $since = $now->startOfDay()->subDays($windowDays);

        // toBase()：不經 Eloquent hydrate。只需要四個純量欄位，model 的
        // casts／事件／關聯一個都用不到，而視窗內可能有數千列。
        $rows = NewsItem::query()
            ->relevant()
            ->where('published_at', '>=', $since)
            ->where('published_at', '<=', $now)
            ->toBase()
            ->get(['title', 'summary', 'domains', 'related_symbols']);

        $out = [];

        foreach ($rows as $row) {
            // toBase() 繞過 model cast，JSON 欄位回來是原始字串。
            $symbols = json_decode((string) $row->related_symbols, true);

            if (! is_array($symbols) || $symbols === []) {
                continue;
            }

            $domains = json_decode((string) $row->domains, true);

            $chains = $this->mapper->map(
                (string) $row->title,
                (string) ($row->summary ?? ''),
                is_array($domains) ? $domains : [],
            );

            foreach ($chains as $chain) {
                $topic = (string) ($chain['key'] ?? '');

                if ($topic === '') {
                    continue;
                }

                foreach ($symbols as $symbol) {
                    if (! is_string($symbol) || $symbol === '') {
                        continue;
                    }

                    $out[$topic][$symbol] = ($out[$topic][$symbol] ?? 0) + 1;
                }
            }
        }

        // 遞減排序放在這裡而不是呼叫端：呼叫端要的是「提及最多的前 N 檔」，
        // 每個呼叫端各自排一次既重複又容易漏。arsort 保留鍵。
        foreach ($out as $topic => $counts) {
            arsort($counts);
            $out[$topic] = $counts;
        }

        return $this->memo[$key] = $out;
    }

    /**
     * 嚴格取值。裸 `(int) config(...)` 缺鍵時會靜默變 0，而 `window_days` 為 0
     * 會把視窗縮到今天、外圍候選幾乎全空，且沒有任何錯誤訊號可供察覺。
     *
     * 用 `is_numeric()` 而非 `isset()`：被覆寫成 `''`／`'abc'` 時 `isset()` 仍回
     * true，接著 `(int) 'abc' === 0` 又回到同一個靜默降級。
     */
    private function requireInt(string $key): int
    {
        $value = config("topics.{$key}");

        if (! is_numeric($value)) {
            throw new RuntimeException("topics.{$key} config 缺失或非數值。");
        }

        return (int) $value;
    }
}
