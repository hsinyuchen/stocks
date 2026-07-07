<?php

namespace App\Console\Commands;

use App\Models\Instrument;
use App\Support\MarketResolver;
use Illuminate\Console\Command;

/**
 * 一次性修正命令：MarketResolver 支援指數前，^ 開頭 symbol 被依 .TW
 * 後綴規則錯標（^TWII → market=US/currency=USD/asset_type=stock）。
 * 依現行 resolver 重算指數 instrument 的 metadata；已正確的 row 不動。
 */
class FixIndexInstrumentMetadataCommand extends Command
{
    protected $signature = 'instruments:fix-index-metadata';

    protected $description = 'Re-derive market/currency/asset_type for index (^-prefixed) instruments via MarketResolver.';

    public function handle(): int
    {
        $indices = Instrument::query()
            ->where('symbol', 'like', '^%')
            ->get();

        $fixed = 0;

        foreach ($indices as $instrument) {
            $instrument->fill([
                'market' => MarketResolver::region($instrument->symbol),
                'currency' => MarketResolver::currency($instrument->symbol),
                'asset_type' => MarketResolver::assetType($instrument->symbol),
            ]);

            if (! $instrument->isDirty()) {
                continue;
            }

            $this->line("  {$instrument->symbol}: ".implode(', ', array_keys($instrument->getDirty())));
            $instrument->save();
            $fixed++;
        }

        $this->info("Fixed {$fixed} of {$indices->count()} index instrument(s).");

        return self::SUCCESS;
    }
}
