<?php

namespace App\Console\Commands;

use App\Models\Instrument;
use App\Support\MarketResolver;
use Illuminate\Console\Command;

/**
 * 一次性修正命令：`MarketResolver::assetType()` 支援 ETF 之前，所有非 `^` 開頭的
 * symbol 一律被標成 `stock`，包含 ETF（實測 QQQ 在 instruments 表裡是 stock，
 * 全站 95 stock／3 index、`etf` 一列都沒有）。
 *
 * 依現行 resolver 重算 `asset_type`；已正確的 row 不動。與
 * {@see FixIndexInstrumentMetadataCommand} 同一個模式，但涵蓋全部標的而不只指數
 * ——ETF 錯標的不是 `^` 開頭那批。
 *
 * **只改 `asset_type`**：`market` 與 `currency` 由指數那支命令負責，兩支各管
 * 一件事，重跑任一支都不會覆蓋另一支修好的欄位。
 */
class FixInstrumentAssetTypeCommand extends Command
{
    protected $signature = 'instruments:fix-asset-type';

    protected $description = 'Re-derive asset_type for all instruments via MarketResolver (adds ETF detection).';

    public function handle(): int
    {
        $instruments = Instrument::query()->orderBy('symbol')->get();
        $fixed = 0;

        foreach ($instruments as $instrument) {
            $instrument->asset_type = MarketResolver::assetType($instrument->symbol);

            if (! $instrument->isDirty()) {
                continue;
            }

            $before = $instrument->getOriginal('asset_type');
            $before = $before instanceof \BackedEnum ? $before->value : (string) $before;

            $this->line("  {$instrument->symbol}: {$before} → {$instrument->asset_type->value}");
            $instrument->save();
            $fixed++;
        }

        $this->info("Fixed {$fixed} of {$instruments->count()} instrument(s).");

        return self::SUCCESS;
    }
}
