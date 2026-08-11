<?php

namespace App\Services\Analysis;

/**
 * rule_signal 的欄位解讀規則。
 *
 * 從 StockAnalysisService 抽出來，因為個股問答需要一字不差的同一份指南——兩邊
 * 看的是同一組 technical_snapshot / chip / margin 欄位，指南一旦分岔，就代表其中
 * 一邊會開始出現另一邊已經修掉的幻覺（把 null 當 0、沒有籌碼資料時自行編造外資
 * 動向、把「融資增加」直接讀成看空）。
 *
 * 無狀態純函式，可直接 new，也可由容器解析。
 */
final class SignalFieldGuide
{
    /**
     * 產生欄位解讀規則。
     *
     * 籌碼與融資段落只在真的有對應資料時才輸出，避免反過來提示模型「應該要有籌碼」。
     *
     * @param  array<string, mixed>  $ruleSignal
     */
    public function forRuleSignal(array $ruleSignal): string
    {
        $lines = [
            '- technical_snapshot 中值為 null 代表指標仍在暖身期、資料不足，不是 0，不得解讀為中性或偏空。',
            '- rule_signal.stance 僅由 KD、MACD 柱狀體、MA5 對 MA20 三項計分（score 範圍 -3 至 3）。三者同為價格動能的衍生指標、彼此高度共線，不可當成三項獨立佐證，也不要據此宣稱「多項指標一致確認」。',
            '- 只能使用本 prompt 提供的數據。不得臆測未提供的資訊（財報細節、法人持股比率、目標價、產能、營收預估）。缺少的資料請直接說明缺少。',
        ];

        $chip = $ruleSignal['chip'] ?? null;

        if (! is_array($chip)) {
            $lines[] = '- 本次未提供籌碼資料（非台股或抓取失敗）。不得臆測三大法人買賣超、外資動向或持股變化。';

            // 仍要走融資指南：兩者各自 best-effort，籌碼抓失敗而融資成功時，
            // rule_signal 裡會有沒被說明的 margin 欄位。
            return implode("\n", $this->appendBrokerBranchGuide($this->appendMarginGuide($lines, $ruleSignal), $ruleSignal));
        }

        $lines[] = '- rule_signal.chip 為台股三大法人買賣超，單位是「股」（1 張 = 1000 股），正值買超、負值賣超；數值為買進減賣出的淨額。';
        $lines[] = '- chip.foreign_net 含外資自營商；chip.dealer_net 含自營商自行買賣與避險兩本帳。chip.days 為採計的交易日數。';
        $lines[] = '- chip.stance：accumulating 為該期間外資淨買超、distributing 為淨賣超、neutral 為相抵。chip.foreign_streak 為外資連續同向天數，淨額 0 視為中斷。';
        $lines[] = '- rule_signal.alignment 描述技術面與籌碼面的關係：confirm 為同向、diverge 為背離、none 為無法判定。';
        $lines[] = '- 背離比同向更有資訊量：價格偏弱但外資買進，可能是打底；價格偏強但外資賣出，可能是出貨。若 alignment 為 diverge，請明確說明這個矛盾及其兩種可能解讀，不要只挑一邊。';
        $lines[] = '- 單日買賣超雜訊大。請以 chip.days 期間的合計與 foreign_streak 為主要依據，不要只憑最後一日下結論。';
        $lines[] = '- 籌碼只反映資金流向，不等於基本面或估值判斷，也不保證後續走勢。';

        return implode("\n", $this->appendBrokerBranchGuide($this->appendMarginGuide($lines, $ruleSignal), $ruleSignal));
    }

    /**
     * 融資融券的欄位指南。
     *
     * 與籌碼分開說明，因為兩者的主體不同：籌碼是法人，融資是散戶槓桿。模型很容易
     * 把「融資增加」直接讀成看空，這裡必須明講那是錯的——多頭初升段融資跟著增加
     * 是正常現象，真正有訊息量的是它與外資的組合（crossover）。
     *
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $ruleSignal
     * @return list<string>
     */
    private function appendMarginGuide(array $lines, array $ruleSignal): array
    {
        $margin = $ruleSignal['margin'] ?? null;

        if (! is_array($margin)) {
            $lines[] = '- 本次未提供融資融券資料（非台股或抓取失敗）。不得臆測融資餘額、券資比或散戶槓桿狀況。';

            return $lines;
        }

        $lines[] = '- rule_signal.margin 為台股融資融券，單位是「股」（1 張 = 1000 股）。margin.balance 為融資餘額、margin.short_balance 為融券餘額。';
        $lines[] = '- margin.usage_percent 是融資餘額佔融資限額的比率；限額依股本而異，故絕對餘額不可跨股比較，要看使用率。null 代表限額不明或暫停信用交易。';
        $lines[] = '- margin.short_ratio 為券資比（融券÷融資）。比率高代表空方部位相對集中，具備軋空條件。';
        $lines[] = '- margin.stance：leveraging 為該期間融資顯著增加（散戶加碼）、deleveraging 為顯著減少（散戶退場）、neutral 為變化未達門檻。';
        $lines[] = '- 重要：融資增加本身不等於看空。多頭初升段融資與股價同步上升是正常現象。只有在融資增速遠超股價漲幅、或融資增加同時法人賣出時，才構成警訊。';
        $lines[] = '- margin.crossover 是融資與外資的交叉判定，資訊量高於單看融資：retail_chasing（融資增＋外資賣，散戶接刀，套牢籌碼累積）、smart_money_absorbing（融資減＋外資買，籌碼由散戶換手到法人）、aligned_long（兩者同步做多，但槓桿在累積）、aligned_short（多殺多，賣壓可能已宣洩）、none（資料不足或任一方中性，不得強行解讀）。';
        $lines[] = '- 融資資料為收盤後公佈的 T 日數字，不反映盤中變化，也不保證後續走勢。';

        return $lines;
    }

    /**
     * 券商分點的欄位指南。
     *
     * 分點是「哪一家券商在買/賣」，反映主力（特定分點）的資金流向，與三大法人籌碼
     * 互補。資料源為 FinMind 贊助等級 dataset，免費 token 抓不到，此時明示未提供、
     * 不得臆測，避免模型編造主力券商。
     *
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $ruleSignal
     * @return list<string>
     */
    private function appendBrokerBranchGuide(array $lines, array $ruleSignal): array
    {
        $broker = $ruleSignal['broker_branch'] ?? null;

        if (! is_array($broker) || ! ($broker['available'] ?? false)) {
            $lines[] = '- 本次未提供券商分點資料（非台股、需 FinMind 贊助等級、或抓取失敗）。不得臆測主力券商、分點買賣超或特定券商進出。';

            return $lines;
        }

        $lines[] = '- rule_signal.broker_branch 為券商分點主力摘要，反映「哪些券商分點在買/賣」，單位是「股」（1 張 = 1000 股）。window_days 為採計交易日數。';
        $lines[] = '- broker_branch.top_buyers / top_sellers 為近期累計淨買超 / 淨賣超前幾大券商，各含 net_shares（淨額）、streak_days（連續同向天數）、days_active（出現天數）。';
        $lines[] = '- broker_branch.concentration.buy_topn_ratio / sell_topn_ratio 為前幾大券商淨額佔全體同向淨額的比率（0~1）：越接近 1 代表買/賣方越集中於少數主力，越低代表越分散。';
        $lines[] = '- 主力券商連續多日買超且集中度高，代表特定資金積極布局；連續賣超且集中，代表主力調節。分點只反映資金流向，非基本面或估值判斷，也不保證後續走勢，且不等於三大法人（外資/投信/自營）。';

        return $lines;
    }
}
