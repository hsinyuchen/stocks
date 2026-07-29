<?php

namespace App\Console\Commands;

use App\Enums\AssetType;
use App\Models\Instrument;
use App\Support\MarketResolver;
use Illuminate\Console\Command;

/**
 * 把 config/screener.universe 的精選清單灌進 instruments 表。
 *
 * 選股股池的來源已改為「標的清單（instruments）∪ 使用者自選股」，config 的
 * universe 從此只是初始種子。沒有這個指令的話，切換來源當下那些只存在於 config
 * 的股票會直接從選股器消失。
 *
 * 可重複執行：已存在的 symbol 一律跳過，不會覆蓋管理員後來改過的名稱。
 */
class SeedInstrumentUniverseCommand extends Command
{
    protected $signature = 'instruments:seed-universe {--dry-run : 只顯示會新增哪些，不寫入}';

    protected $description = '將 config/screener.universe 的精選標的補進標的清單';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $existing = Instrument::query()->pluck('id', 'symbol')->all();

        $created = 0;
        $skipped = 0;
        $pending = [];

        foreach ((array) config('screener.universe', []) as $entry) {
            $symbol = strtoupper(trim((string) ($entry['symbol'] ?? '')));

            if ($symbol === '') {
                continue;
            }

            if (isset($existing[$symbol])) {
                $skipped++;

                continue;
            }

            $name = (string) ($entry['name'] ?? $symbol);
            $pending[] = [$symbol, $name];

            if (! $dryRun) {
                Instrument::query()->create([
                    'symbol' => $symbol,
                    'name' => $name !== '' ? $name : $symbol,
                    'market' => MarketResolver::region($symbol),
                    'asset_type' => MarketResolver::assetType($symbol),
                    'currency' => MarketResolver::currency($symbol),
                    'exchange' => null,
                ]);
            }

            $existing[$symbol] = true;
            $created++;
        }

        foreach ($pending as [$symbol, $name]) {
            $this->line(sprintf('  + %-10s %s', $symbol, $name));
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%s新增 %d 筆、已存在跳過 %d 筆。',
            $dryRun ? '[dry-run] 將' : '',
            $created,
            $skipped,
        ));

        // 指數不該進選股池（對指數算 KD 交叉沒有意義），提醒管理員確認。
        $indexCount = Instrument::query()->where('asset_type', AssetType::Index->value)->count();

        if ($indexCount > 0) {
            $this->components->warn(sprintf(
                '標的清單另有 %d 筆指數，選股器會自動排除，無需手動處理。',
                $indexCount,
            ));
        }

        return self::SUCCESS;
    }
}
