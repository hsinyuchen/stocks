<?php

namespace App\Services\Analysis;

use App\Data\IndustryMomentum;
use App\Data\SocialArbitrage;
use App\Enums\IndustryMomentumUnavailableReason;
use App\Enums\SocialArbitrageInsufficientReason;
use App\Enums\SocialArbitrageStage;
use App\Services\Fundamentals\IndustryMomentumSampler;
use App\Services\Social\SocialArbitrageClassifier;
use App\Support\SocialArbitrageVerdicts;

/**
 * 社交套利分類與產業動能的 prompt 區塊。依 locale 產生，與 SopGuide、
 * RatesNarrative、OrderInventoryGuide 同一模式。
 *
 * 這裡只做呈現，**不做任何判斷**：分類、各腿判定、產業中位數全部由
 * {@see SocialArbitrageClassifier} 與
 * {@see IndustryMomentumSampler} 決定。本類別唯一的
 * 「決定」是把機器鍵翻成可讀文字，並把該腿的原始數值與門檻一起印出來——只給
 * 「法人買：是」是把一條武斷的線包裝成事實，使用者無從判斷結論離門檻有多遠。
 *
 * **兩個區塊刻意分開（BEGIN_SOCIAL_ARBITRAGE 與 BEGIN_INDUSTRY_MOMENTUM）**，
 * 而不是塞進同一對分隔線：
 *
 * - 兩者的資料來源與時間語意完全不同。社交套利量的是近 14 個日曆日的新聞、股價、
 *   籌碼；產業動能比的是已公布的月營收 YoY。合在一起，「本分類只涵蓋新聞熱度」
 *   這句硬性聲明會被 LLM 讀成也涵蓋月營收，而「回顧性指標」這條紀律也會被套到
 *   分類階段上。
 * - 可得性不同。社交套利永遠有一個分類（classifier 最差回 Insufficient），
 *   產業動能則可能整個不適用（非台股／產業未知）。分開才能讓其中一個只剩一句
 *   「不適用＋原因」，而不必在另一個區塊裡插入一段與它無關的缺席說明。
 * - 引用紀律逐條指名區塊。規則 4 只約束產業動能，指向一個混裝的區塊會讓
 *   「不得講成對未來的預測」看起來也在約束分類階段。
 *
 * 兩個區塊共用同一份引用紀律（{@see self::discipline()}）：它們同時出現、同時
 * 缺席（都由同一個 Instrument 反查而來），拆成兩段紀律只會讓規則段多一個標題。
 *
 * **每一段缺席時整段略過，不輸出「無」或「N/A」**：空欄位會被 LLM 當成有意義的
 * 否定訊號。唯一的例外是產業動能整個不適用時仍輸出一行原因——那不是佔位字，
 * 是「這個市場沒有這個功能」與「資料還沒到」的區別，不寫就會被讀成後者。
 */
class SocialArbitrageGuide
{
    /**
     * 引用紀律的規則本文（不含編號）。編號在 discipline() 依實際輸出的條數重編，
     * 與 OrderInventoryGuide 同一手法。
     *
     * @var array<string, string>
     */
    private const RULES_ZH = [
        'block_source' => '社交套利的分類與各條腿的判定，一律以 BEGIN_SOCIAL_ARBITRAGE 區塊為準，不得自行推算或臆測。',
        'news_only' => '不得把「社交套利」講成涵蓋社群輿情：本平台只有新聞熱度，YouTube、X、Reddit、Threads、PTT、Dcard、電商通路一個都沒有接入。',
        'unevaluable' => '標示為「無法評估」的腿不得當成否定結論來推論；「無法人籌碼資料」不是「法人沒買」。',
        'momentum_retrospective' => '產業動能（BEGIN_INDUSTRY_MOMENTUM 區塊）比的是已公布的月營收，是回顧性指標，不得講成對未來營收或股價的預測。',
        'no_backtest' => '兩個區塊的門檻都未經回測，分類與產業動能只能當描述性標籤，不得轉述成勝率、報酬或後續走勢的預測。',
    ];

    /** @var array<string, string> */
    private const RULES_EN = [
        'block_source' => 'Take the social-arbitrage category and every leg verdict only from the BEGIN_SOCIAL_ARBITRAGE block; do not recompute or infer them yourself.',
        'news_only' => 'Never describe "social arbitrage" as covering social-media sentiment: this platform has news heat only — YouTube, X, Reddit, Threads, PTT, Dcard, and e-commerce channels are all unconnected.',
        'unevaluable' => 'A leg marked "cannot be evaluated" must not be read as a negative verdict; "no institutional-flow data" does not mean "institutions did not buy".',
        'momentum_retrospective' => 'Industry momentum (the BEGIN_INDUSTRY_MOMENTUM block) compares monthly revenue that has already been published; it is backward-looking and must never be presented as a forecast of future revenue or price.',
        'no_backtest' => 'The thresholds behind both blocks have never been backtested; the category and the momentum reading are descriptive labels only and must not be restated as a hit rate, a return, or a prediction of subsequent price action.',
    ];

    private function en(string $locale): bool
    {
        return $locale === 'en';
    }

    /**
     * 讀一則面向使用者的固定文案，缺鍵或空字串直接拋錯。
     *
     * 不用裸 `config()`：階段 3 的 Task 7 踩過——純量 config 讀取缺鍵時靜默回 null，
     * `(string) null === ''`，整段文案無聲消失且沒有任何錯誤訊號。這裡的每一則都
     * 沒有退路：機器鍵沒有對照就是把 `partly_priced` 送給 LLM 照抄給使用者看，
     * 硬性聲明缺一則就是少講一件必須講的事。config 缺鍵是部署問題，讓它拋出來。
     */
    private function copy(string $group, string $key, bool $en): string
    {
        $path = sprintf('order_inventory.narrative.%s.%s', $en ? $group.'_en' : $group, $key);
        $value = config($path);

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException("$path config 缺失，無法產生社交套利／產業動能區塊。");
        }

        return $value;
    }

    /**
     * 讀一個門檻，缺鍵或非數值一律拋錯。
     *
     * 與 SocialArbitrageClassifier::requireFloat() 同一個理由，但這裡的失效模式不同：
     * 判定是 classifier 算的，這裡只是**印出來給人看的那條線**——裸 `(float)` 轉型
     * 會讓「已漲門檻 +0.0%」與「未達買超門檻」並列在同一行，看起來像判定寫錯，
     * 而實際是文案讀不到設定。
     */
    private function threshold(string $path): float
    {
        $value = config($path);

        if (! is_numeric($value)) {
            throw new \RuntimeException("$path config 缺失或非數值，無法輸出社交套利區塊的門檻。");
        }

        return (float) $value;
    }

    private function intThreshold(string $path): int
    {
        return (int) $this->threshold($path);
    }

    /** 比率轉百分比。一律帶正負號：淨買超與漲幅都可能是負的，省略符號會讀成漲。 */
    private function percent(float $ratio): string
    {
        return sprintf('%+.1f%%', $ratio * 100);
    }

    /** 已經是百分點的值（毛利率 QoQ）。 */
    private function points(float $pp): string
    {
        return sprintf('%+.1fpp', $pp);
    }

    /** 兩個比率相減得到的百分點（產業超額與其門檻）。 */
    private function pointsFromRatio(float $ratio): string
    {
        return sprintf('%+.1fpp', $ratio * 100);
    }

    /**
     * 社交套利分類區塊。
     *
     * @param  SocialArbitrage  $arbitrage  由 SocialArbitrageAssessor 產出，本方法不重算任何判定
     */
    public function arbitrageBlock(SocialArbitrage $arbitrage, string $locale = 'zh'): string
    {
        $en = $this->en($locale);
        $lines = [];

        // 1. 分類。機器鍵必須翻成可讀文字，否則 LLM 會把 `partly_priced` 照抄出去。
        $lines[] = ($en ? '- Stage: ' : '- 分類：')
            .$this->copy('social', 'stage_'.$arbitrage->stage->value, $en);

        // 2. 資料不足的細分原因。六種處境（新聞才 2 則／熱度沒升溫／股價算不出來／
        // 灰帶／反向大跌／湊不成任何一桶）在使用者眼中必須分得出來。
        if ($arbitrage->insufficientReason instanceof SocialArbitrageInsufficientReason) {
            $lines[] = ($en ? '- Insufficient reason: ' : '- 資料不足原因：')
                .$this->copy('social', 'reason_'.$arbitrage->insufficientReason->value, $en);
        }

        // 3. 涵蓋面與門檻性質。兩則都是硬性聲明，不得省略：SOP 2.3 列的社群來源
        // 本平台一個都沒接，而門檻沒做過任何預測力回測。
        $lines[] = ($en ? '- Coverage: ' : '- 涵蓋面：').$this->copy('social', 'coverage_note', $en);
        $lines[] = ($en ? '- Threshold provenance: ' : '- 門檻性質：').$this->copy('social', 'no_backtest_note', $en);

        // 4. 四條腿：判定 + 原始值 + 門檻。
        $lines[] = $this->heatLine($arbitrage, $en);

        $highWater = $this->highWaterLine($arbitrage, $en);
        if ($highWater !== null) {
            $lines[] = $highWater;
        }

        $lines[] = $this->priceLine($arbitrage, $en);
        $lines[] = $this->foreignLine($arbitrage, $en);
        $lines[] = ($en ? '- Revenue leg: ' : '- 營收腿：').$this->revenueVerdict($arbitrage, $en);
        $lines[] = $this->marginLine($arbitrage, $en);

        // 5. 視窗長度。三條腿落在同一段日曆視窗上是本分類的前提，不寫的話
        // 「同視窗漲幅」跨了多久無從得知。
        $window = $this->intThreshold('order_inventory.social.heat_window_days');
        $lines[] = $en
            ? sprintf('- Comparison window: the most recent %d calendar days against the preceding %d calendar days.', $window, $window)
            : sprintf('- 比較視窗：近 %d 個日曆日，對照前一個 %d 個日曆日。', $window, $window);

        return implode("\n", $lines);
    }

    /**
     * 新聞熱度腿：新期／前期則數、樣本下限、升溫門檻。
     *
     * 變化率為 null（前期 0 則，除以 0 無定義）時**整段略過那半句**，不印
     * 「變化 0.0%」——那會把「算不出來」講成「沒有變化」，而前期 0 則恰好是
     * 最強的升溫訊號。
     */
    private function heatLine(SocialArbitrage $arbitrage, bool $en): string
    {
        $heat = $arbitrage->heat;
        $floor = $this->intThreshold('order_inventory.social.min_recent_mentions');
        $rise = $this->percent($this->threshold('order_inventory.social.heat_rise_ratio'));

        // 判定鍵一律走 SocialArbitrageVerdicts：面板端讀的是同一組鍵，兩邊各寫一次
        // match 會讓優先序在其中一邊被改掉而沒有訊號。
        $verdict = $this->copy('social', SocialArbitrageVerdicts::heat($arbitrage), $en);

        $change = $heat->changeRatio === null
            ? ''
            : ($en
                ? sprintf(', change %s', $this->percent($heat->changeRatio))
                : sprintf('，變化 %s', $this->percent($heat->changeRatio)));

        return $en
            ? sprintf(
                '- News heat: %d mentions in the recent window vs %d in the prior window%s (sample floor %d mentions, heat-rise threshold %s) → %s',
                $heat->recentCount, $heat->priorCount, $change, $floor, $rise, $verdict,
            )
            : sprintf(
                '- 新聞熱度：新期 %d 則、前期 %d 則%s（新期樣本下限 %d 則、升溫門檻 %s）→ %s',
                $heat->recentCount, $heat->priorCount, $change, $floor, $rise, $verdict,
            );
    }

    /**
     * 熱度高檔那一行。門檻算不出來（歷史太短、分佈全空、百分位落在 0 則）時
     * **整行不輸出**——印一個 0.0 則的門檻會讓剛被報導的標的立刻看起來像高檔。
     */
    private function highWaterLine(SocialArbitrage $arbitrage, bool $en): ?string
    {
        $heat = $arbitrage->heat;

        $key = SocialArbitrageVerdicts::highWater($arbitrage);

        if ($key === null) {
            return null;
        }

        $verdict = $this->copy('social', $key, $en);

        return $en
            ? sprintf(
                '- Heat high-water mark: historical threshold %.1f mentions, %d this window, %s',
                $heat->highWaterThreshold, $heat->recentCount, $verdict,
            )
            : sprintf(
                '- 熱度高檔：近期歷史門檻 %.1f 則，本期 %d 則，%s',
                $heat->highWaterThreshold, $heat->recentCount, $verdict,
            );
    }

    /**
     * 股價腿。四個門檻全列：分類的差別在「市場已經反應了多少」，只給一個門檻
     * 看不出結論離哪條線最近。
     */
    private function priceLine(SocialArbitrage $arbitrage, bool $en): string
    {
        $prefix = $en ? '- Price leg: ' : '- 股價腿：';

        $key = SocialArbitrageVerdicts::price($arbitrage);

        // 不可評估時**不印任何數字**：priceChange 為 null 進 sprintf 會被印成 0。
        if ($key === 'price_unevaluable') {
            return $prefix.$this->copy('social', $key, $en);
        }

        $verdict = $this->copy('social', $key, $en);

        $risen = $this->percent($this->threshold('order_inventory.social.price_risen'));
        $surged = $this->percent($this->threshold('order_inventory.social.price_surged'));
        $flat = $this->percent($this->threshold('order_inventory.social.price_flat'));
        $fell = $this->percent($this->threshold('order_inventory.social.price_fell'));

        return $prefix.($en
            ? sprintf(
                'same-window change %s (already-up threshold %s, sharp-rise threshold %s, not-materially-up ceiling %s, sharp-fall floor %s) → %s',
                $this->percent($arbitrage->priceChange), $risen, $surged, $flat, $fell, $verdict,
            )
            : sprintf(
                '同視窗漲幅 %s（已漲門檻 %s、大漲門檻 %s、未顯著漲上界 %s、反向大跌下界 %s）→ %s',
                $this->percent($arbitrage->priceChange), $risen, $surged, $flat, $fell, $verdict,
            ));
    }

    /**
     * 法人腿。**分母寫「同期成交量」而不是「股本」**：spec 原文寫佔股本比，但本專案
     * 沒有任何流通股數來源，實際算的是佔同期成交量（見 config 註解與 commit 1ab7420）。
     * 寫成股本是對 LLM 與使用者陳述一個系統沒有在算的東西。
     *
     * 不可評估時**不印任何數字**：`null` 印成「佔同期成交量 0.0%」等於把「沒有這種
     * 資料」講成「有資料且為零」，而美股恆為不可評估。
     */
    private function foreignLine(SocialArbitrage $arbitrage, bool $en): string
    {
        $prefix = $en ? '- Institutional leg: ' : '- 法人腿：';

        $key = SocialArbitrageVerdicts::foreign($arbitrage);

        if ($key === 'foreign_unevaluable') {
            return $prefix.$this->copy('social', $key, $en);
        }

        $verdict = $this->copy('social', $key, $en);

        $buy = $this->percent($this->threshold('order_inventory.social.foreign_net_buy_volume_share'));
        $heavy = $this->percent($this->threshold('order_inventory.social.foreign_net_buy_volume_share_heavy'));

        return $prefix.($en
            ? sprintf(
                'foreign net buying as a share of same-window trading volume %s (net-buying threshold %s, heavy-buying threshold %s) → %s',
                $this->percent($arbitrage->foreignVolumeShare), $buy, $heavy, $verdict,
            )
            : sprintf(
                '外資淨買超佔同期成交量 %s（買超門檻 %s、大買門檻 %s）→ %s',
                $this->percent($arbitrage->foreignVolumeShare), $buy, $heavy, $verdict,
            ));
    }

    /** 營收腿的原始輸入本身就是布林（訂單庫存框架的 C1），沒有數值可印。 */
    private function revenueVerdict(SocialArbitrage $arbitrage, bool $en): string
    {
        return $this->copy('social', SocialArbitrageVerdicts::revenue($arbitrage), $en);
    }

    /** 毛利腿。「下滑」用的是階段 2 C2 的持平帶而不是 0，所以門檻要寫出來。 */
    private function marginLine(SocialArbitrage $arbitrage, bool $en): string
    {
        $prefix = $en ? '- Gross-margin leg: ' : '- 毛利腿：';

        $key = SocialArbitrageVerdicts::margin($arbitrage);

        if ($key === 'margin_unevaluable') {
            return $prefix.$this->copy('social', $key, $en);
        }

        $verdict = $this->copy('social', $key, $en);
        $band = $this->points($this->threshold('order_inventory.thresholds.gross_margin_stable_pp'));

        return $prefix.($en
            ? sprintf(
                'quarter-over-quarter change %s (stable-band floor %s) → %s',
                $this->points($arbitrage->grossMarginQoqPp), $band, $verdict,
            )
            : sprintf(
                '毛利率季變動 %s（持平帶下界 %s）→ %s',
                $this->points($arbitrage->grossMarginQoqPp), $band, $verdict,
            ));
    }

    /**
     * 產業動能區塊。
     *
     * 不適用時只有一行原因，**不補「無」「N/A」，也不印半套數字**：IndustryMomentum
     * 在 applicable = false 時刻意不帶任何數字，留著半套會讓人以為可以拿來比較。
     */
    public function momentumBlock(IndustryMomentum $momentum, string $locale = 'zh'): string
    {
        $en = $this->en($locale);

        if (! $momentum->applicable) {
            // reason 依 DTO 契約在 applicable = false 時必為非 null；防呆時退回
            // NotTaiwan 以外的第三種說法會是編造，所以直接讓對照查詢拋錯。
            $reason = $momentum->reason instanceof IndustryMomentumUnavailableReason
                ? $momentum->reason->value
                : IndustryMomentumUnavailableReason::IndustryUnknown->value;

            return ($en ? '- Industry momentum not applicable: ' : '- 產業動能不適用：')
                .$this->copy('industry_momentum', 'unavailable_'.$reason, $en);
        }

        $lines = [];

        if ($momentum->industry !== null && $momentum->industry !== '') {
            // 產業別是 FinMind 的 industry_category 原文（中文），不翻譯——
            // 專有名詞被機器翻壞比夾雜一個中文詞更糟，理由同 OrderInventoryGuide。
            $lines[] = ($en ? '- Industry: ' : '- 產業別：').$momentum->industry;
        }

        // 樣本數一律寫出來（0 也寫）：不寫會讓使用者以為系統看過整個產業。
        $lines[] = $en
            ? sprintf('- Peer sample: %d filings (excluding this symbol)', $momentum->samples)
            : sprintf('- 同業樣本：%d 檔（不含本標的）', $momentum->samples);

        $minSamples = $this->intThreshold('order_inventory.industry_momentum.min_samples');

        if ($momentum->median === null) {
            // 「樣本還不夠」與「這個市場沒有這個功能」是兩件事，文案必須不同。
            $lines[] = ($en ? '- Peer median not reported: ' : '- 未提供同業中位數：')
                .$this->copy('industry_momentum', 'insufficient_samples', $en)
                .($en ? sprintf(' (%d).', $minSamples) : sprintf('（%d 檔）。', $minSamples));
        } else {
            $lines[] = ($en ? '- Peer monthly-revenue YoY median: ' : '- 同業月營收 YoY 中位數：')
                .$this->percent($momentum->median)
                .($en
                    ? sprintf(' (industry-acceleration threshold %s)', $this->percent($this->threshold('order_inventory.industry_momentum.industry_accelerating')))
                    : sprintf('（產業加速門檻 %s）', $this->percent($this->threshold('order_inventory.industry_momentum.industry_accelerating'))));
        }

        // 自身 YoY 與超額缺席時整行略過。印「無」會被讀成「查過而且沒有」，
        // 印 0 更糟——0 是合法的 YoY，也是「與產業同步」這個實質宣稱。
        if ($momentum->own !== null) {
            $lines[] = ($en ? '- This symbol monthly-revenue YoY: ' : '- 本標的月營收 YoY：')
                .$this->percent($momentum->own);
        }

        if ($momentum->excess !== null) {
            $lines[] = ($en ? '- Excess (this symbol − peer median): ' : '- 超額（本標的 − 產業中位數）：')
                .$this->pointsFromRatio($momentum->excess)
                .($en
                    ? sprintf(' (outperformance threshold %s)', $this->pointsFromRatio($this->threshold('order_inventory.industry_momentum.outperformance')))
                    : sprintf('（個股跑贏門檻 %s）', $this->pointsFromRatio($this->threshold('order_inventory.industry_momentum.outperformance'))));
        }

        // 回顧性聲明是硬性輸入不是可選補充：名字叫「動能」，不寫就會被讀成前瞻指標。
        $lines[] = ($en ? '- Nature of the measure: ' : '- 指標性質：')
            .$this->copy('industry_momentum', 'retrospective_note', $en);
        $lines[] = ($en ? '- Threshold provenance: ' : '- 門檻性質：')
            .$this->copy('industry_momentum', 'no_backtest_note', $en);

        return implode("\n", $lines);
    }

    /**
     * 引用紀律，放進 prompt 的規則段。兩個資料區塊共用同一份。
     *
     * 最重要的兩條：不得把本分類講成涵蓋社群輿情（第 2 條），以及不可評估的腿
     * 不得當成否定結論（第 3 條）——後者是美股的常態，讀錯會讓 LLM 以為
     * 三條腿都驗過。
     */
    public function discipline(string $locale = 'zh'): string
    {
        $en = $this->en($locale);
        $rules = $en ? self::RULES_EN : self::RULES_ZH;

        $lines = [$en ? 'BEGIN_SOCIAL_ARBITRAGE citation discipline:' : 'BEGIN_SOCIAL_ARBITRAGE 引用紀律：'];
        $number = 0;

        foreach ($rules as $rule) {
            $lines[] = sprintf('%d. %s', ++$number, $rule);
        }

        return implode("\n", $lines);
    }

    /**
     * 分類的可讀文字。
     *
     * public：日後若有摘要模式（自選股快報的點名段落只給一行）需要同一份對照，
     * 必須走這裡而不是另抄一份——OrderInventoryGuide::ratingLabel() 已為同一情境
     * 立過先例，兩處各自維護的對照表遲早漂移。
     */
    public function stageLabel(SocialArbitrageStage $stage, string $locale = 'zh'): string
    {
        return $this->copy('social', 'stage_'.$stage->value, $this->en($locale));
    }
}
