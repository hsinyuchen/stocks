<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Services\News\NewsClassifier;
use Illuminate\Console\Command;

/**
 * 以目前的分類規則重跑既有新聞的 domain / domains / relevant。
 *
 * 分類結果是寫入時計算的，因此調整 config/news.php 的關鍵字或排除清單之後，
 * 既有資料仍停留在舊判定。新增 relevant 欄位時尤其明顯：migration 的預設值是
 * true，所有歷史資料都會被當成相關，過濾等於沒作用。
 *
 * related_symbols 採聯集，只新增不移除——既有值可能來自 provider 或個股新聞
 * 抓取，不是分類器判出的，重跑不該把它們洗掉。
 */
class ReclassifyNewsCommand extends Command
{
    protected $signature = 'news:reclassify {--dry-run : 只顯示變更統計，不寫入}';

    protected $description = '以目前的分類規則重新標記既有新聞的領域與相關性';

    public function handle(NewsClassifier $classifier): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;
        $domainChanged = 0;
        $relevanceChanged = 0;
        $symbolsAdded = 0;

        NewsItem::query()->chunkById(500, function ($items) use (
            $classifier, $dryRun, &$total, &$domainChanged, &$relevanceChanged, &$symbolsAdded
        ): void {
            foreach ($items as $item) {
                $total++;

                $result = $classifier->classify((string) $item->title, (string) $item->summary);

                $symbols = array_values(array_unique(array_merge(
                    (array) ($item->related_symbols ?? []),
                    $result['symbols'],
                )));

                $relevant = $result['relevant'] || $symbols !== [];

                if ($item->domain !== $result['domain']) {
                    $domainChanged++;
                }

                if ((bool) $item->relevant !== $relevant) {
                    $relevanceChanged++;
                }

                if (count($symbols) > count((array) ($item->related_symbols ?? []))) {
                    $symbolsAdded++;
                }

                if ($dryRun) {
                    continue;
                }

                $item->forceFill([
                    'domain' => $result['domain'],
                    'domains' => $result['domains'],
                    'relevant' => $relevant,
                    'related_symbols' => $symbols,
                ])->save();
            }
        });

        $this->info(sprintf(
            '%s：掃描 %d 筆，領域變更 %d、相關性變更 %d、新增關聯個股 %d 筆。',
            $dryRun ? '試跑' : '重新分類完成',
            $total,
            $domainChanged,
            $relevanceChanged,
            $symbolsAdded,
        ));

        return self::SUCCESS;
    }
}
