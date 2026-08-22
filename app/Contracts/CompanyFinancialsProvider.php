<?php

namespace App\Contracts;

use App\Data\OrderInventoryData;

/**
 * 營運資金相關的季度財報序列，供訂單／庫存／進貨備料判斷使用。
 *
 * 與既有 FundamentalsProvider 分開，因為關注點不同：那個回估值快照
 * （PER/PBR/殖利率/TTM EPS/ROE），這個回時間序列。兩者共用同一次上游抓取
 * 與同一份 24 小時快取，避免為了型別潔癖多打一次 FinMind——資產負債表
 * 本來就在抓，而免費層額度會撞（FinMindGate 存在即為此）。
 *
 * best-effort：抓不到回 OrderInventoryData::empty()，不拋。
 */
interface CompanyFinancialsProvider
{
    /**
     * @param  string  $symbol  含市場後綴的代號，如 2330.TW / NVDA
     * @param  int  $months  回溯月數；框架要求近 8 季，故通常為 30
     */
    public function financials(string $symbol, int $months): OrderInventoryData;
}
