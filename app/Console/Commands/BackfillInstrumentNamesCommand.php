<?php

namespace App\Console\Commands;

use App\Models\Instrument;
use App\Services\Search\FinMindStockSearchProvider;
use App\Services\Search\YahooStockSearchProvider;
use App\Support\MarketResolver;
use Illuminate\Console\Command;

class BackfillInstrumentNamesCommand extends Command
{
    protected $signature = 'instruments:backfill-names';

    protected $description = 'Fill company names for instruments whose name is still the bare symbol, via the search providers.';

    public function handle(FinMindStockSearchProvider $taiwan, YahooStockSearchProvider $us): int
    {
        $instruments = Instrument::query()
            ->where(fn ($q) => $q->whereColumn('name', 'symbol')->orWhere('name', ''))
            ->get();

        $updated = 0;

        foreach ($instruments as $instrument) {
            $name = $this->resolveName($instrument->symbol, $taiwan, $us);

            if ($name !== null && $name !== '' && $name !== $instrument->symbol) {
                $instrument->update(['name' => $name]);
                $updated++;
                $this->line("  {$instrument->symbol} -> {$name}");
            }
        }

        $this->info("Backfilled {$updated} of {$instruments->count()} instrument name(s).");

        return self::SUCCESS;
    }

    private function resolveName(string $symbol, FinMindStockSearchProvider $taiwan, YahooStockSearchProvider $us): ?string
    {
        if (MarketResolver::isTaiwan($symbol)) {
            $results = $taiwan->search(MarketResolver::taiwanCode($symbol));
        } else {
            $results = $us->search($symbol);
        }

        foreach ($results as $result) {
            if (($result['symbol'] ?? null) === $symbol) {
                return $result['name'] ?? null;
            }
        }

        return null;
    }
}
