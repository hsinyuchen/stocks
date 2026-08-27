<?php

namespace App\Console\Commands;

use App\Contracts\MarketDataProvider;
use App\Enums\AssetType;
use App\Models\Instrument;
use App\Services\Market\MarketBearishFlipDetector;
use App\Services\Screener\ScreenerService;
use Illuminate\Console\Command;

/**
 * 預載價格快取。**這是全站唯一會主動刷新 daily_prices 的東西。**
 *
 * 在它被排進 `routes/console.php` 之前，價格只在有人呼叫 `dailyPrices()` 時才抓，
 * 於是實測 67 檔有價格的標的裡有 31 檔停在 15–30 天前——它們早就過了
 * `CachedMarketDataProvider::isFresh()` 的 TTL，只是自從被批次拉進來後沒有任何人
 * 再碰過。技術面的新鮮度 gate（`config/health.php` 的 technical 區塊）會把那些
 * 標的的技術立場全部判成不可評估，而**根治在這裡不在 gate**。
 *
 * **刷新要含指數，掃描不要——這兩件事的清單刻意不同。**
 * {@see ScreenerService::baseSymbols()} 排除指數，理由是「對 ^TWII 算 KD 黃金交叉
 * 沒有意義，且會佔掉掃描的時間預算」。那個理由只適用於**掃描**：刷新價格既不做
 * 判定也沒有時間預算，而 {@see MarketBearishFlipDetector} 用 `^TWII` 的收盤與
 * ma20／ma60 判「同時跌破月線與季線」——那是技術判斷，吃的是同一個過期問題。
 * 實測 98 個 instrument 裡有價格的 67 檔中，恰好 3 檔不在股池：^GSPC、^IXIC、^TWII。
 *
 * 所以指數在**本指令裡**補上，不去改 `baseSymbols()`——改那裡會把指數帶進掃描，
 * 污染的是另一個功能。
 */
class ScreenerWarmCommand extends Command
{
    protected $signature = 'screener:warm';

    protected $description = '預載選股器股池與指數的價格資料（建立/更新 daily_prices 快取），可重複執行。';

    public function handle(MarketDataProvider $marketData, ScreenerService $screener): int
    {
        $universe = $this->refreshTargets($screener);
        // config/screener.php 定義的是 90。**不要在這裡另給一個預設值**——`?:` 之外
        // 的第二參數只在鍵不存在時生效，而它存在，所以任何寫在這裡的數字都是死的，
        // 只會讓讀 code 的人以為真正的視窗是那個數字。
        $days = (int) config('screener.history_days');
        $ok = 0;
        $failed = [];

        if ($universe === []) {
            $this->warn('標的清單是空的。請先於 /admin/instruments 新增，或執行 php artisan instruments:seed-universe。');

            return self::SUCCESS;
        }

        foreach (array_keys($universe) as $symbol) {
            try {
                $marketData->dailyPrices($symbol, $days);
                $ok++;
                $this->line("  {$symbol} ok");
            } catch (\Throwable $exception) {
                $failed[] = $symbol;
                $this->warn("  {$symbol} failed: {$exception->getMessage()}");
            }
        }

        $this->info("預載完成：{$ok} / ".count($universe).' 支成功。');

        if ($failed !== []) {
            $this->warn('失敗（重跑本命令即可重試）：'.implode(', ', $failed));
        }

        return self::SUCCESS;
    }

    /**
     * 要刷新的標的：掃描股池 **＋ 指數**。
     *
     * 股池那一半與掃描共用同一份清單（讀 config 的話會回到「預載了 A、實際掃描的
     * 是 B」，那正是股池來源統一要解決的問題）；指數是這裡額外補的，理由見類別
     * docblock。以 symbol 當鍵天然去重——指數不會出現在 `baseSymbols()` 裡，
     * 但真的哪天出現了，也只會被抓一次。
     *
     * @return array<string, string> symbol => name
     */
    private function refreshTargets(ScreenerService $screener): array
    {
        $indices = Instrument::query()
            ->where('asset_type', AssetType::Index->value)
            ->orderBy('symbol')
            ->get(['symbol', 'name'])
            ->mapWithKeys(fn (Instrument $instrument): array => [
                strtoupper((string) $instrument->symbol) => (string) $instrument->name,
            ])
            ->all();

        return $screener->baseSymbols() + $indices;
    }
}
