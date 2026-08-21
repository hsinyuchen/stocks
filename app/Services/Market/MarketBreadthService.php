<?php

namespace App\Services\Market;

use App\Services\Futures\FuturesDataService;
use App\Services\Rates\RatesRegimeService;

/**
 * 大盤風向：全市場三大法人現貨買賣超、期貨/選擇權籌碼、美債利率環境，組成儀表板的一個面板。
 *
 * 三者都是盤後才變的市場層級資料，各自快取（現貨在 MarketInstitutionalService，
 * 利率在 YieldCurveService）。全 best-effort：抓不到的區塊標 available=false，
 * 不影響其他區塊，也不擋儀表板。
 */
class MarketBreadthService
{
    public function __construct(
        private readonly MarketInstitutionalService $institutional,
        private readonly FuturesDataService $futures,
        private readonly RatesRegimeService $rates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'institutional' => $this->institutionalBlock(),
            'futures' => $this->futuresBlock(),
            'rates' => $this->ratesBlock(),
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

    /**
     * 美債利率環境。與台股籌碼區塊互不影響：曲線抓不到時 available=false，
     * 其餘區塊照常。
     *
     * 只回傳天期 key（如 '10y'），不回傳顯示用 label：這份 snapshot 是全站
     * 共用的快取，不能挾帶語系相依內容，顯示文字交由前端 i18n 依 key 解析。
     *
     * @return array<string, mixed>
     */
    private function ratesBlock(): array
    {
        return $this->rates->current()->toArray() + [
            'long_tenor' => (string) config('rates.spread.long', '10y'),
            'short_tenor' => (string) config('rates.spread.short', '3m'),
        ];
    }
}
