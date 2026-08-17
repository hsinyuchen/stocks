<?php

namespace App\Services\Analysis;

/**
 * 社交選股／個股分析 SOP v2 的 prompt 區塊產生器。
 *
 * 來源：docs/social-stock-sop-v2.md（Opus High 審查整合版）。把 SOP 的硬性紀律
 * （免責、T1–T5 來源分級、資料時效、可交易性、部位風險、社群防操縱、加權評分、
 * 輸出格式 v2）抽成可複用、依 locale 的區塊，供個股分析（全套）、個股問答（僅紀律）、
 * 權值/自選（僅共通紀律）組裝，避免四處重複。
 *
 * 無狀態純函式，可直接 new 或由容器解析（比照 SignalFieldGuide）。
 *
 * 一律用 nowdoc（<<<'TXT'）承載文字：SOP 內含大量字面 $（價位、$...$ 數學語法），
 * nowdoc 不插值，天然避開 heredoc 的 $ 被當變數名的陷阱（見
 * project-prompt-heredoc-dollar-escape）。
 */
final class SopGuide
{
    private function en(string $locale): bool
    {
        return $locale === 'en';
    }

    /** 免責：非投資建議。 */
    public function disclaimer(string $locale): string
    {
        if ($this->en($locale)) {
            return <<<'EN'
DISCLAIMER: The following is personal research reference only — not investment advice, not a buy/sell recommendation, and not a guarantee of profit. Every rating, entry, stop, and target is for reference and may lead to loss of principal. This method is not fully backtested; hit rate, return, and max drawdown are unknown. The user bears all trading outcomes.
EN;
        }

        return <<<'ZH'
免責：以下僅供個人研究參考，非投資建議、非買賣推介、非獲利保證。所有評級、買點、停損、停利皆為參考，可能導致本金損失。本方法未經完整回測，命中率、報酬率、最大回撤未知。使用者自行承擔全部交易結果。
ZH;
    }

    /** T1–T5 資料來源分級。 */
    public function sourceTiers(string $locale): string
    {
        if ($this->en($locale)) {
            return <<<'EN'
SOURCE TIERS — tag every number and conclusion with its tier and data date:
- T1 first-hand official (MOPS, TWSE/TPEx, SEC EDGAR, filings, earnings calls, official releases): may be a primary basis.
- T2 second-hand aggregators (Yahoo, Goodinfo, etc.): cross-check with T1 or another T2.
- T3 media narrative (news, financial TV, headlines): a lead only, never a standalone numeric basis.
- T4 social sampling (X, Reddit, PTT, Dcard, Threads, YouTube comments, public IG/FB): low-confidence corroboration only.
- T5 estimates (dealer flows, broker-branch, cost basis, traffic estimates): mark as estimate; never a primary basis.
EN;
        }

        return <<<'ZH'
資料來源分級——每個數字與結論都標示來源層級與資料日期：
- T1 一手官方（MOPS、TWSE/TPEx、SEC EDGAR、財報、法說、官方新聞稿）：可作主要結論依據。
- T2 二手彙整（Yahoo、鉅亨、Goodinfo 等）：需與 T1 或另一 T2 交叉確認。
- T3 媒體敘事（新聞、財經節目、標題）：只作題材線索，不單獨作為數字依據。
- T4 社群抽樣（X、Reddit、PTT、Dcard、Threads、YouTube 留言、公開 IG/FB）：只作低信心旁證。
- T5 推估（主力進出、券商分點、法人成本、流量估算）：須標示為推估，不作主要依據。
ZH;
    }

    /** 資料時效標註。 */
    public function dataFreshness(string $locale): string
    {
        if ($this->en($locale)) {
            return <<<'EN'
DATA FRESHNESS — state the quote status (close/intraday/delayed/pre/post) and note delays: TW institutional flows are post-close (not intraday); monthly revenue is filed by the 10th of the next month; quarterly/annual reports follow statutory deadlines; broker-branch/dealer flows are estimates; US 13F lags up to 45 days after quarter-end; short interest is settled with a lag; Google Trends is a relative index, not absolute volume.
EN;
        }

        return <<<'ZH'
資料時效——標示報價狀態（收盤/盤中/延遲/盤前/盤後）並注意延遲：台股三大法人為收盤後公布（非即時盤中）；月營收次月 10 日前公告；季報/年報依法定申報期限；主力進出/分點為推估；美股 13F 季末後最多 45 天才公布；short interest 結算與公布有延遲；Google Trends 為相對指數非絕對搜尋量。
ZH;
    }

    /**
     * 「查不到就寫資料不足」紀律，並明示本平台缺哪些資料源。
     */
    public function dataSufficiency(string $locale): string
    {
        if ($this->en($locale)) {
            return <<<'EN'
DATA-SUFFICIENCY DISCIPLINE: if something cannot be found, write "insufficient data" — never fill the gap by guessing. This platform has NO live feed for social heat, e-commerce sales, Google Trends, trade-show/product-launch data, or attention/disposition-stock (注意股/處置股) status. For those dimensions, unless the supplied news corroborates them, mark "not checked / insufficient data"; do not fabricate social heat, e-commerce signals, or any "main force" beyond the broker-branch data provided. Social heat alone cannot establish a conclusion — it needs product, revenue, order, earnings-call, or official corroboration.
EN;
        }

        return <<<'ZH'
資料不足紀律：查不到就寫「資料不足」，不得用猜測補洞。本平台無社群熱度、電商銷售、Google Trends、展會/產品發表、注意股/處置股狀態的即時資料源。這些面向除非提供的新聞可佐證，一律標「未查/資料不足」；不得編造社群熱度、電商訊號，或券商分點以外的任何「主力」。社群熱度不能單獨成立結論，須有產品、營收、訂單、法說或官方佐證。
ZH;
    }

    /** 可交易性硬門檻。 */
    public function tradabilityCheck(string $locale): string
    {
        if ($this->en($locale)) {
            return <<<'EN'
TRADABILITY CHECK before any entry suggestion: attention/disposition/full-cash-delivery status (mark "not checked" — no feed); liquidity via recent volume if available else "not checked"; risk of limit-up/down lock making stops unexecutable; costs (commission, transaction tax, slippage, FX, sub-brokerage); imminent events (earnings, ex-dividend, AGM, disposition-release). If any item cannot be confirmed, downgrade the call to "observe only, do not chase; reassess after tradability is confirmed."
EN;
        }

        return <<<'ZH'
可交易性檢查（給任何進場建議前）：注意股/處置股/全額交割狀態（無資料源，標「未查」）；流動性以近期成交量判斷，無則標「未查」；是否可能漲跌停鎖死使停損失效；成本（手續費、證交稅、滑價、匯率、複委託）；即將公布的重大事件（法說、財報、除權息、股東會、處置解除）。任一無法確認，操作建議改為「只觀察，不追價；待可交易性確認後再評估」。
ZH;
    }

    /** 部位與風險管理。 */
    public function positionRisk(string $locale): string
    {
        if ($this->en($locale)) {
            return <<<'EN'
POSITION & RISK: a rating is not a position size. Single-trade risk (entry to stop) must have a cap as a fixed fraction of total capital; no over-concentration in one name; combine exposure within one theme (AI/robotics/CPO); if a Top-N list shares one supply chain, treat it as a single-theme exposure; set a stop-trading line on consecutive errors or monthly drawdown.
EN;
        }

        return <<<'ZH'
部位與風險：評級不是部位大小。單筆風險（進場到停損）須設總資金固定比例上限；單檔不可過度集中；同一主題（AI/機器人/CPO）需合併曝險；Top 名單若同屬一條供應鏈，視為單一主題曝險；連續錯誤或月度回撤達門檻時設停手線。
ZH;
    }

    /** 社群防操縱與時序分層。 */
    public function antiManipulation(string $locale): string
    {
        if ($this->en($locale)) {
            return <<<'EN'
SOCIAL-SIGNAL ANTI-MANIPULATION: rising social heat may be a distribution signal (KOL sponsorship, advisory-group pumping, bots, spam reposts, headline packaging, faked reviews). Rules: a social signal without T1/T2 corroboration is capped at low confidence; if heat spikes only AFTER a sharp price rise, judge it "already priced / overheated," not early; if a few accounts drive most of the discussion, lower credibility; heavy financial-TV/mainstream coverage is usually a mid-to-late signal. Timing: early (official new product, show demo, certification, first earnings-call mention, niche expert discussion), mid (industry media, supply-chain news, monthly-revenue inflection, first analyst coverage), late (heavy YouTube, forum frenzy, clickbait, already-spiked price).
EN;
        }

        return <<<'ZH'
社群防操縱：社群升溫可能是出貨訊號（KOL 業配、投顧群組拉抬、水軍假帳號、同質洗版、標題包裝、評價灌水）。規則：社群訊號無 T1/T2 佐證最高只能低信心；若股價已急漲後社群才爆量，判「已反映/過熱」而非早期；少數帳號貢獻多數討論則可信度降低；財經節目與大眾媒體密集通常為中後段訊號。時序分層：早期（官網新品、展會 Demo、認證、法說首次提及、小眾專業討論）、中期（產業媒體、供應鏈新聞、月營收轉折、法人首次覆蓋）、晚期（YouTube 密集、PTT/Dcard 熱議、標題黨、股價已急漲）。
ZH;
    }

    /** 八面向加權評分、A/B/C 閾值、信心等級、一票否決（僅個股分析）。 */
    public function scoringRubric(string $locale): string
    {
        if ($this->en($locale)) {
            return <<<'EN'
SCORING RUBRIC (weighted, produce a total then a letter grade):
- Fundamentals 20% (revenue, EPS, gross margin, cash flow, report quality)
- Future 15% (new product/customer/market, industry trend)
- Explosiveness 10% (re-rating, theme diffusion, order ramp potential)
- Social/product signal 10% (product heat, discussion, news narrative, show visibility)
- Chips 15% (institutions, main force, margin, ETF/institutional flow)
- Technicals 10% (moving averages, price-volume, support/resistance, trend)
- Valuation 15% (PE, PB, PS, peers, whether growth supports valuation)
- Risk deduction -15% (theme miss, liquidity, overheated valuation, compliance, report doubts)
Grades: >=75 A (high priority, still must pass tradability); 55-74 B (opportunity, wait for price/catalyst/confirmation); <55 C (not a primary candidate).
Confidence: High (key data mostly T1/T2 and counter-evidence searched), Medium (main data reliable but some theme/social unverified), Low (mostly news/social/estimates or too many gaps). A low-confidence A is treated as B; a low-confidence B is observe-only.
ONE-VOTE VETO (downgrade/exclude regardless of score): theme has only social buzz with no T1/T2 support; revenue/report contradicts the theme; material report doubt/litigation/delisting/full-cash-delivery risk; attention/disposition status makes stops unexecutable; insufficient recent volume; too many insufficient-data dimensions to form an auditable conclusion.
EN;
        }

        return <<<'ZH'
評分規則（加權，先算總分再給等級）：
- 基本面 20%（營收、EPS、毛利率、現金流、財報品質）
- 未來性 15%（新產品/新客戶/新市場、產業趨勢）
- 爆發性 10%（重新估值、主題擴散、訂單放量可能）
- 社交/產品訊號 10%（產品熱度、社群討論、新聞敘事、展會能見度）
- 籌碼 15%（法人、主力、融資、ETF/機構資金）
- 技術面 10%（均線、量價、支撐壓力、趨勢結構）
- 估值 15%（PE、PB、PS、同業、成長性是否支撐估值）
- 風險扣分 -15%（題材落空、流動性、估值過熱、法遵、財報疑慮）
評級：≥75 A（高優先，仍須通過可交易性）；55–74 B（有機會，等價位/催化劑/確認）；<55 C（暫不列主力）。
信心等級：高（關鍵數據多為 T1/T2 且完成反證搜尋）、中（主要數據可靠但部分題材/社群待驗證）、低（多依賴新聞/社群/推估或資料不足過多）。低信心 A 視同 B 處理；低信心 B 只觀察不操作。
一票否決（不論總分，降級或排除）：題材只有社群聲量無 T1/T2 佐證；營收/財報與題材相反；重大財報疑慮/訴訟/下市/全額交割風險；注意股/處置股致停損不可執行；近期成交量不足；資料不足面向過多無法形成可稽核結論。
ZH;
    }

    /** 標準輸出格式 v2（僅個股分析）。 */
    public function outputFormatV2(string $locale): string
    {
        if ($this->en($locale)) {
            return <<<'EN'
OUTPUT FORMAT — respond in Markdown with these sections in order:
## Conclusion first
- Rating: A/B/C
- Confidence: High/Medium/Low
- Action: observe / wait for entry / small position OK / do not chase / avoid
- Note: not investment advice, bear your own risk
## Sources & freshness
- Quote date; main tiers (T1-T5); insufficient-data items
## Fundamentals
- Revenue, EPS, gross margin, valuation
## Theme & future
- Product/customer/project; 6-24 month scenario; counter-evidence
## Product / channel / social heat
- Product visibility; e-commerce/channel signal; social heat; signal credibility; manipulation risk; social-arbitrage call: early / already priced / overheated / false signal / insufficient data
## Chips & technicals
- Institutions/main force/margin; moving averages/price-volume/support-resistance
## Tradability check
- Attention/disposition: yes/no/not checked; liquidity: enough/insufficient/not checked; stop executable: high/med/low; costs included: yes/no
## Position & risk
- Single-trade risk; same-theme exposure; stop/target; invalidation conditions
## Final strategy
- Short-term; swing; medium-term
EN;
        }

        return <<<'ZH'
輸出格式——請用 Markdown，依序輸出以下段落：
## 先看結論
- 評級：A / B / C
- 信心等級：高 / 中 / 低
- 操作：可觀察 / 等買點 / 可小部位 / 不追 / 避開
- 聲明：非投資建議，請自行承擔風險
## 資料來源與時效
- 報價日期；主要來源層級（T1-T5）；資料不足項目
## 基本面
- 營收、EPS、毛利率、估值
## 題材與未來性
- 產品/客戶/專案；6–24 個月情境；反證點
## 產品 / 通路 / 社群熱度
- 產品能見度；電商/通路訊號；社群熱度；訊號可信度；是否可能被操縱；社交套利判斷：早期 / 已反映 / 過熱 / 假訊號 / 資料不足
## 籌碼與技術
- 法人/主力/融資；均線/量價/支撐壓力
## 可交易性檢查
- 注意股/處置股：是/否/未查；流動性：足夠/不足/未查；停損可執行性：高/中/低；交易成本納入：是/否
## 部位與風險
- 單筆風險；同主題曝險；停損/停利；失效條件
## 最終策略
- 短線；波段；中期
ZH;
    }
}
