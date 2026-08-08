<?php

namespace App\Services\Market;

use App\Services\Futures\FuturesDataService;

/**
 * 大盤風向：全市場三大法人現貨買賣超 ＋ 期貨/選擇權籌碼，組成儀表板的一個面板。
 *
 * 兩者都是盤後才變的大盤層級資料，各自快取（現貨快取在 MarketInstitutionalService）。
 * 全 best-effort：抓不到的區塊標 available=false，不影響另一區塊，也不擋儀表板。
 */
class MarketBreadthService
{
    public function __construct(
        private readonly MarketInstitutionalService $institutional,
        private readonly FuturesDataService $futures,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'institutional' => $this->institutionalBlock(),
            'futures' => $this->futuresBlock(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function institutionalBlock(): array
    {
        $data = $this->institutional->latest();

        return [
            'available' => $data->hasAny(),
            'date' => $data->date,
            'foreign_net' => $data->foreignNet,
            'trust_net' => $data->trustNet,
            'dealer_net' => $data->dealerNet,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function futuresBlock(): array
    {
        if (! (bool) config('brief.futures.enabled', true)) {
            return ['available' => false, 'enabled' => false];
        }

        $snapshot = $this->futures->snapshot();

        return [
            'available' => $snapshot->hasAny(),
            'enabled' => true,
            'date' => $snapshot->date,
            'futures_close' => $snapshot->futuresClose,
            'futures_open_interest' => $snapshot->futuresOpenInterest,
            'foreign_net_oi' => $snapshot->foreignNetOi,
            'trust_net_oi' => $snapshot->trustNetOi,
            'dealer_net_oi' => $snapshot->dealerNetOi,
            'put_call_ratio' => $snapshot->putCallRatio(),
            'option_put_oi' => $snapshot->optionPutOi,
            'option_call_oi' => $snapshot->optionCallOi,
        ];
    }
}
