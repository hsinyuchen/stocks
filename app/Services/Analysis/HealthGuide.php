<?php

namespace App\Services\Analysis;

use App\Data\HealthBlockResult;
use App\Data\HealthInputSnapshot;
use App\Data\LongTermRead;
use App\Data\ShortTermRead;
use App\Enums\HealthBlock;
use App\Enums\HealthUnavailableReason;
use App\Enums\HealthVerdict;
use App\Services\Health\LongTermHealthReader;
use App\Services\Health\ShortTermHealthReader;
use App\Services\SignalEngine;

/**
 * 短線／中長線體質判讀的 prompt 區塊。依 locale 產生，與 SopGuide、
 * OrderInventoryGuide、SocialArbitrageGuide 同一模式。
 *
 * 這裡只做呈現，**不做任何判斷**：兩個立場、背離旗標與四塊判定全部由
 * {@see ShortTermHealthReader} 與 {@see LongTermHealthReader} 決定，本類別唯一的
 * 「決定」是把機器鍵翻成可讀文字，並把每一項的資料日期接在判定後面。
 *
 * **逐項日期不是可選補充。** 實測資料庫裡價格、籌碼、財報分別停在三個不同的
 * 日期（2026-08-25／08-17／08-05）；只給一個「資料日」會讓使用者以為整份判讀
 * 是同一天算的，而那正是這個功能最容易誤導人的地方。
 *
 * **RSI 與量能一定要標明未參與判定。** 它們與 KD／MACD／均線同為價格動能的
 * 衍生量、彼此高度共線；列在同一個區塊裡而不加註，會被讀成第四、第五項佐證。
 *
 * 文案一律走 config 的兩本字典（`health.narrative` 與 `health.narrative_en`），
 * 缺鍵直接拋錯而不是靜默略過——階段 3 踩過純量 config 讀取缺鍵回 null、
 * `(string) null === ''`，整段文案無聲消失且沒有任何錯誤訊號。
 *
 * **已知的雙語缺口（標籤是英文，理由維持中文）**：`technicalReasons`、
 * `chipReasons` 與四塊的 `reasons` 由 {@see SignalEngine} 與
 * {@see LongTermHealthReader} 直接以繁中字串產生（兩者都不吃 locale，也沒有機器
 * 鍵對照表），到了呈現層已經是定稿，沒有可翻譯的空間。這些內容**不能因此在
 * 英文路徑被丟掉**：理由正是使用者判斷可信度的依據，只給判定不給理由等於要求
 * 使用者無條件相信一組未經回測的門檻。所以英文路徑照樣輸出，段落標籤用英文、
 * 值原樣保留——與 OrderInventoryGuide 對 `fixedCaveats`／`industryNote` 的既有
 * 處理一致。正解是讓兩個 reader 改輸出機器鍵、由本類別依 locale 渲染，但那要動
 * 階段 5b Task 3、4 已定稿的純計算類別，不在本任務（呈現與保存）範圍內。
 */
class HealthGuide
{
    /**
     * 技術立場的可能值。`insufficient_data` 不在其中：{@see ShortTermRead} 已把它
     * 轉成 null（與四塊的「不可評估」同一語意），呈現層只需處理一種缺席。
     *
     * @var list<string>
     */
    public const TECHNICAL_STANCES = ['bullish', 'bearish', 'watch', 'neutral'];

    /**
     * 籌碼立場的可能值，即 {@see SignalEngine} 的 `chip.stance`。
     *
     * @var list<string>
     */
    public const CHIP_STANCES = ['accumulating', 'distributing', 'neutral'];

    /**
     * 不隨資料變動、每次都要能查到的固定文案鍵。
     *
     * @var list<string>
     */
    public const FIXED_NOTE_KEYS = [
        'verdict_unavailable',
        'as_of_unknown',
        'diverging_yes',
        'diverging_no',
        'context_note',
        'cached_only_note',
        'unadjusted_price_note',
        'no_backtest_note',
        'no_total_note',
    ];

    /**
     * 引用紀律的規則本文（不含編號）。編號在 discipline() 依實際輸出的條數重編，
     * 與 OrderInventoryGuide／SocialArbitrageGuide 同一手法。
     *
     * @var array<string, string>
     */
    private const RULES_ZH = [
        'read_source' => '短線的兩個立場與中長線四塊的判定，一律以 BEGIN_HEALTH_READ 區塊為準，不得自行推算或重算。',
        'collinear' => '技術面的 KD、MACD 與均線高度共線，不得當成三項獨立佐證；RSI 與量能只是脈絡，未參與判定。',
        'no_offset' => '技術面與籌碼面背離時不得互相抵銷，兩者都要講。',
        'unavailable' => '標示為「不可評估」的塊不等於「不成立」，必須連同成因一起轉述。',
        'unadjusted_price' => '價格未做除權息還原，除權息與拆股會在技術指標上留下真實缺口，技術面結論的可信度受此限制。',
    ];

    /** @var array<string, string> */
    private const RULES_EN = [
        'read_source' => 'Take the two short-term stances and all four long-term block verdicts only from the BEGIN_HEALTH_READ block; never recompute or infer them yourself.',
        'collinear' => 'KD, MACD and the moving averages are highly collinear; they are not three independent confirmations. RSI and volume are context only and take no part in any verdict.',
        'no_offset' => 'When the technical and chip stances diverge they must not be netted against each other; report both.',
        'unavailable' => 'A block marked "cannot be evaluated" does not mean "does not hold"; always restate the reason it could not be evaluated.',
        'unadjusted_price' => 'Prices are not adjusted for dividends or splits, so ex-dividend dates and splits leave real gaps in the technical indicators; the confidence of any technical conclusion is limited by this.',
    ];

    /**
     * 本類別會引用的全部 narrative 鍵（點路徑）。
     *
     * 存在理由是測試：既有的雙語 parity 只驗兩本對稱，**兩本同時漏掉同一個鍵它
     * 照樣綠**。從「被引用的鍵」這一端檢查才擋得住。三個 enum 逐一展開而不是抄
     * 一份靜態清單，新增一個 case 卻忘了寫文案時才會立刻紅。
     *
     * @return list<string>
     */
    public static function narrativeKeys(): array
    {
        $keys = self::FIXED_NOTE_KEYS;

        foreach (self::TECHNICAL_STANCES as $stance) {
            $keys[] = 'technical_stance.'.$stance;
        }

        foreach (self::CHIP_STANCES as $stance) {
            $keys[] = 'chip_stance.'.$stance;
        }

        foreach (HealthVerdict::cases() as $verdict) {
            $keys[] = 'verdicts.'.$verdict->value;
        }

        foreach (HealthBlock::cases() as $block) {
            $keys[] = 'blocks.'.$block->value;
        }

        foreach (HealthUnavailableReason::cases() as $reason) {
            $keys[] = 'unavailable_reasons.'.$reason->value;
        }

        return array_values($keys);
    }

    private function en(string $locale): bool
    {
        return $locale === 'en';
    }

    /**
     * 讀一則面向使用者的文案，缺鍵或空字串直接拋錯。
     *
     * 沒有退路可走：機器鍵沒有對照就是把 `not_in_universe` 送給 LLM 照抄給使用者
     * 看，固定聲明缺一則就是少講一件必須講的事。config 缺鍵是部署問題。
     */
    private function copy(string $key, bool $en): string
    {
        $path = sprintf('health.%s.%s', $en ? 'narrative_en' : 'narrative', $key);
        $value = config($path);

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException("$path config 缺失，無法產生體質判讀區塊。");
        }

        return $value;
    }

    /**
     * 資料日期。**null 也要輸出一個明講「無」的值**，不是整段省略：省略會讓
     * 讀者以為那一項與前一項同一天。
     */
    private function asOf(?string $date, bool $en): string
    {
        $value = $date ?? $this->copy('as_of_unknown', $en);

        return $en ? sprintf(' (as of %s)', $value) : sprintf('（資料日：%s）', $value);
    }

    private function separator(bool $en): string
    {
        return $en ? '; ' : '；';
    }

    /**
     * 判讀區塊。
     *
     * 三個參數缺一不可：兩份判讀給結論與理由，snapshot 給出處（取用政策與逐項
     * 日期的來源）。**送進 prompt 的就是這三份，保存下來的也是這三份**，
     * 否則幾天後頁面顯示新判讀、而歷史分析的文字仍引用生成當下的舊判讀。
     */
    public function block(ShortTermRead $short, LongTermRead $long, HealthInputSnapshot $snapshot, string $locale = 'zh'): string
    {
        $en = $this->en($locale);
        $lines = [];

        // 1. 兩個立場各自一行，各自帶自己的資料日。價格與籌碼的公佈日不同步
        // （實測差了 8 個交易日），共用一個日期是假的。
        $lines[] = ($en ? '- Technical stance: ' : '- 技術立場：')
            .$this->stance($short->technicalStance, 'technical_stance', $en)
            .$this->asOf($short->priceAsOf, $en);

        $lines[] = ($en ? '- Chip stance: ' : '- 籌碼立場：')
            .$this->stance($short->chipStance, 'chip_stance', $en)
            .$this->asOf($short->chipAsOf, $en);

        // 2. 背離旗標。這是本設計的核心：兩者背離時不互相抵銷，壓成一個數字會讓
        // 「技術偏多但法人在賣」與「兩邊都沒訊號」變成同一格。
        $lines[] = ($en ? '- Technical vs chip divergence: ' : '- 技術與籌碼是否背離：')
            .$this->copy($short->diverging ? 'diverging_yes' : 'diverging_no', $en);

        // 3. 立場的理由。只給立場不給理由，使用者無從判斷可信度。
        if ($short->technicalReasons !== []) {
            $lines[] = ($en ? '- Technical reasons: ' : '- 技術面理由：')
                .implode($this->separator($en), $short->technicalReasons);
        }

        if ($short->chipReasons !== []) {
            $lines[] = ($en ? '- Chip reasons: ' : '- 籌碼面理由：')
                .implode($this->separator($en), $short->chipReasons);
        }

        // 4. 脈絡欄位，並當場說明它們未參與判定。
        $lines[] = $this->contextLine($short, $en);

        // 5. 中長線四塊。**不可評估的塊也要留著**，帶著成因——刪掉它們，使用者
        // 只會看到一份比較短的清單，而不知道少了什麼、為什麼少。
        foreach ($long->blocks as $block) {
            $lines[] = $this->blockLine($block, $en);
        }

        // 6. 出處與硬性聲明。
        $lines[] = ($en ? '- Formula version: ' : '- 判讀公式版本：').$long->formulaVersion;
        $lines[] = ($en ? '- Bars used for technicals: ' : '- 技術面採計 K 棒數：').$snapshot->bars;

        if ($snapshot->cachedOnly) {
            $lines[] = ($en ? '- Data policy: ' : '- 取用政策：').$this->copy('cached_only_note', $en);
        }

        $lines[] = ($en ? '- Price adjustment: ' : '- 價格還原：').$this->copy('unadjusted_price_note', $en);
        $lines[] = ($en ? '- No composite score: ' : '- 沒有總分：').$this->copy('no_total_note', $en);
        $lines[] = ($en ? '- Threshold provenance: ' : '- 門檻性質：').$this->copy('no_backtest_note', $en);

        return implode("\n", $lines);
    }

    /** 立場的可讀文字；null 代表資料不足，與四塊的「不可評估」同一個字。 */
    private function stance(?string $stance, string $map, bool $en): string
    {
        return $stance === null
            ? $this->copy('verdict_unavailable', $en)
            : $this->copy($map.'.'.$stance, $en);
    }

    /**
     * RSI 與量能。**缺值時印「不可評估」而不是 0**：暖身期的指標是 null，
     * 而 0 在 RSI 的量尺上是極度超賣，語意完全相反。
     */
    private function contextLine(ShortTermRead $short, bool $en): string
    {
        $unavailable = $this->copy('verdict_unavailable', $en);

        $rsi = $short->rsi === null ? $unavailable : sprintf('%.1f', $short->rsi);
        $volume = $short->volumeRatio === null
            ? $unavailable
            : ($en ? sprintf('%.2fx the 20-day average', $short->volumeRatio) : sprintf('為 20 日均量的 %.2f 倍', $short->volumeRatio));

        return $en
            ? sprintf('- Context (no part in any verdict): RSI %s, volume %s. %s', $rsi, $volume, $this->copy('context_note', $en))
            : sprintf('- 脈絡（未參與判定）：RSI %s、成交量%s。%s', $rsi, $volume, $this->copy('context_note', $en));
    }

    /**
     * 一塊的判定行：名稱、判定（或不可評估）、資料日、理由（或成因）。
     *
     * 不可評估時成因**必須**跟著出現：五種成因對使用者是五種不同的行動，
     * 只寫「不可評估」等於把「永遠不會有」與「等一下就有」混成同一件事。
     */
    private function blockLine(HealthBlockResult $result, bool $en): string
    {
        $label = $this->copy('blocks.'.$result->block->value, $en);

        $verdict = $result->verdict instanceof HealthVerdict
            ? $this->copy('verdicts.'.$result->verdict->value, $en)
            : $this->copy('verdict_unavailable', $en);

        $tail = $result->unavailableReason instanceof HealthUnavailableReason
            ? $this->copy('unavailable_reasons.'.$result->unavailableReason->value, $en)
            : implode($this->separator($en), $result->reasons);

        $line = $en
            ? sprintf('- %s: %s%s', $label, $verdict, $this->asOf($result->asOf, $en))
            : sprintf('- %s：%s%s', $label, $verdict, $this->asOf($result->asOf, $en));

        return $tail === '' ? $line : $line.($en ? ' — ' : '——').$tail;
    }

    /**
     * 引用紀律，放進 prompt 的規則段。
     *
     * 最重要的兩條：立場與判定不得自行推算（第 1 條），以及背離不得互相抵銷
     * （第 3 條）——後者是本設計的核心，讀錯會把兩個相反的訊號抵成「沒訊號」。
     */
    public function discipline(string $locale = 'zh'): string
    {
        $en = $this->en($locale);
        $rules = $en ? self::RULES_EN : self::RULES_ZH;

        $lines = [$en ? 'BEGIN_HEALTH_READ citation discipline:' : 'BEGIN_HEALTH_READ 引用紀律：'];
        $number = 0;

        foreach ($rules as $rule) {
            $lines[] = sprintf('%d. %s', ++$number, $rule);
        }

        return implode("\n", $lines);
    }
}
