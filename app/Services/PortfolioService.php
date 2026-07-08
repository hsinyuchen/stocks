<?php

namespace App\Services;

use App\Contracts\MarketDataProvider;
use App\Models\Holding;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PortfolioService
{
    public function __construct(private readonly MarketDataProvider $marketData) {}

    /**
     * 持倉損益摘要，依幣別分組。
     *
     * 不做匯率換算（設計決策）：TWD 與 USD 各自小計，不加總為單一幣別。
     *
     * @return array{groups: list<array<string, mixed>>, unavailable: list<array{symbol: string, reason: string}>}
     */
    public function summary(User $user): array
    {
        $rowsByCurrency = [];
        $unavailable = [];

        foreach ($user->holdings()->with('instrument')->get() as $holding) {
            [$row, $failure] = $this->row($holding);

            if ($failure !== null) {
                $unavailable[] = $failure;
            }

            $rowsByCurrency[$holding->currency][] = $row;
        }

        ksort($rowsByCurrency);

        $groups = [];

        foreach ($rowsByCurrency as $currency => $rows) {
            $groups[] = [
                'currency' => $currency,
                'holdings' => $rows,
                'subtotal' => $this->subtotal($rows),
            ];
        }

        return ['groups' => $groups, 'unavailable' => $unavailable];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array{symbol: string, reason: string}|null}
     */
    private function row(Holding $holding): array
    {
        $symbol = $holding->instrument->symbol;

        // decimal cast 回傳 string，運算與輸出前一律顯式轉 float。
        $shares = (float) $holding->shares;
        $avgCost = (float) $holding->avg_cost;
        $costBasis = $shares * $avgCost;

        $price = null;
        $asOf = null;
        $failure = null;

        try {
            $quote = $this->marketData->quote($symbol);
            $price = (float) $quote->price;
            $asOf = $quote->asOf;
        } catch (\Throwable $exception) {
            Log::warning('portfolio: quote unavailable', [
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);
            $failure = ['symbol' => $symbol, 'reason' => $exception->getMessage()];
        }

        $marketValue = $price === null ? null : round($shares * $price, 2);
        $unrealizedPnl = $marketValue === null ? null : round($marketValue - $costBasis, 2);

        return [[
            'id' => $holding->id,
            'symbol' => $symbol,
            'name' => $holding->instrument->name,
            'shares' => $shares,
            'avg_cost' => $avgCost,
            'price' => $price,
            'cost_basis' => round($costBasis, 2),
            'market_value' => $marketValue,
            'unrealized_pnl' => $unrealizedPnl,
            'return_pct' => $this->returnPct($marketValue, $costBasis),
            'as_of' => $asOf,
            'note' => $holding->note,
        ], $failure];
    }

    /** 無報價或成本為 0（贈與股）時回 null，避免除零與虛假報酬率。 */
    private function returnPct(?float $marketValue, float $costBasis): ?float
    {
        if ($marketValue === null || $costBasis <= 0.0) {
            return null;
        }

        return round(($marketValue / $costBasis - 1) * 100, 2);
    }

    /**
     * 小計只累計「有報價」的持倉：無報價者不得以成本價冒充市值，
     * 否則損益會被灌成 0，比缺資料更誤導。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float|null>
     */
    private function subtotal(array $rows): array
    {
        $priced = array_filter($rows, static fn (array $row): bool => $row['price'] !== null);

        $marketValue = (float) array_sum(array_column($priced, 'market_value'));
        $costBasis = (float) array_sum(array_column($priced, 'cost_basis'));

        return [
            'market_value' => round($marketValue, 2),
            'cost_basis' => round($costBasis, 2),
            'unrealized_pnl' => round($marketValue - $costBasis, 2),
            'return_pct' => $this->returnPct($marketValue, $costBasis),
        ];
    }
}
