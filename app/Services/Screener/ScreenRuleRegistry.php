<?php

namespace App\Services\Screener;

use App\Services\Screener\Rules\AboveMa20;
use App\Services\Screener\Rules\BelowMa20;
use App\Services\Screener\Rules\CashFlowDiverging;
use App\Services\Screener\Rules\EarlySocialArbitrage;
use App\Services\Screener\Rules\ForeignBuyingStreak;
use App\Services\Screener\Rules\ForeignSellingStreak;
use App\Services\Screener\Rules\HighMarginUsage;
use App\Services\Screener\Rules\HighReturnOnEquity;
use App\Services\Screener\Rules\HighShortRatio;
use App\Services\Screener\Rules\IndustryOutperformer;
use App\Services\Screener\Rules\InstitutionalAccumulation;
use App\Services\Screener\Rules\InventoryDeteriorating;
use App\Services\Screener\Rules\KdDeathCross;
use App\Services\Screener\Rules\KdGoldenCross;
use App\Services\Screener\Rules\LowMarginUsage;
use App\Services\Screener\Rules\LowValuation;
use App\Services\Screener\Rules\MacdBullishCross;
use App\Services\Screener\Rules\RatedBPlus;
use App\Services\Screener\Rules\RetailChasing;
use App\Services\Screener\Rules\RevenueGrowth;
use App\Services\Screener\Rules\RsiOverbought;
use App\Services\Screener\Rules\RsiOversold;
use App\Services\Screener\Rules\SmartMoneyAbsorbing;
use App\Services\Screener\Rules\StockingUpStarted;
use App\Services\Screener\Rules\VolumeSurge;

class ScreenRuleRegistry
{
    /** @return array<string, ScreenRule> key → rule */
    public function all(): array
    {
        $rules = [
            // 技術面
            new KdGoldenCross, new KdDeathCross,
            new AboveMa20, new BelowMa20,
            new MacdBullishCross,
            new RsiOversold, new RsiOverbought,
            new VolumeSurge,
            // 籌碼面（僅台股）：與上列技術規則正交，上列全是價格動能的一階衍生。
            new ForeignBuyingStreak, new ForeignSellingStreak, new InstitutionalAccumulation,
            // 基本面（僅台股）
            new LowValuation, new RevenueGrowth, new HighReturnOnEquity,
            // 融資融券（僅台股）：籌碼是法人資金流向，融資是散戶槓桿，兩者主體
            // 不同。交叉型規則（後兩條）同時讀兩種資料，資訊量高於單看任一邊。
            new LowMarginUsage, new HighMarginUsage, new HighShortRatio,
            new SmartMoneyAbsorbing, new RetailChasing,
            // 訂單庫存：跨財報序列的複合判斷（僅台股／存貨佔比達標的美股），與上列
            // 單一指標規則正交——後者看單一時點的價格/籌碼/基本面快照，這裡看的是
            // 訂單庫存框架跨季串聯後的評級與條件組合。
            new RatedBPlus, new StockingUpStarted, new InventoryDeteriorating, new CashFlowDiverging,
            // 社交套利與產業動能：兩者都不是價格／籌碼的衍生量，與上列全部正交。
            // 前者的輸入是新聞熱度（本專案唯一的輿情訊號），後者比的是同產業月營收
            // YoY 的相對位置——兩條都在問「這檔相對於外部環境如何」，而不是「這檔
            // 自己的數字如何」。
            new EarlySocialArbitrage, new IndustryOutperformer,
        ];

        $out = [];
        foreach ($rules as $rule) {
            $out[$rule->key()] = $rule;
        }

        return $out;
    }

    /**
     * 呈現用的規則清單：選股器與警報兩個消費端**共用這一份**。
     *
     * 形狀原本各自寫在 ScreenerController 與 AlertController 裡。兩份一樣的
     * `['key' => ..., 'label' => ...]` 意味著加欄位時只改到一邊——而這裡要加的
     * 正是「不說就會讓使用者推論出系統沒驗證過的事」的硬性揭露
     * （見 {@see ScreenRuleNote}），漏一邊等於那個消費端繼續騙人。
     * 收攏成一個方法之後，漏不掉。
     *
     * `notes` 對沒有附註的規則是空陣列而不是 null：呈現層一律 map，
     * 不必先判斷型別。
     *
     * @return list<array{key: string, label: string, notes: list<string>}>
     */
    public function listing(): array
    {
        $out = [];

        foreach ($this->all() as $rule) {
            $out[] = [
                'key' => $rule->key(),
                'label' => $rule->label(),
                'notes' => $rule instanceof ScreenRuleNote ? $rule->noteKeys() : [],
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
