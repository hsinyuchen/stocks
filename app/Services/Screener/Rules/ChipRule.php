<?php

namespace App\Services\Screener\Rules;

use App\Data\ChipFlowData;
use App\Services\Screener\Rules\Concerns\ChipNeutralBand;
use App\Services\Screener\ScreenRule;

/**
 * 籌碼規則的基底。
 *
 * 這類規則與既有的純技術規則正交：現有 9 條全是價格動能的一階衍生（KD、MACD、
 * MA、RSI 都由收盤價推導），彼此高度共線；三大法人買賣超反映的是資金流向，
 * 是獨立的資訊來源。
 *
 * 籌碼僅台股有，非台股一律不命中——回 false 而非 true，避免美股在勾選籌碼
 * 規則時被當成「無條件通過」而混進結果。
 */
abstract class ChipRule implements ScreenRule
{
    use ChipNeutralBand;

    /** 採計的交易日數，與 SignalEngine 的籌碼窗口一致。 */
    protected const WINDOW = 5;

    /** @return list<string> */
    public function requires(): array
    {
        return [ScreenRule::NEEDS_CHIP];
    }

    public function matches(array $series, array $context = []): bool
    {
        $flows = $context[ScreenRule::NEEDS_CHIP] ?? null;

        if (! is_array($flows) || $flows === []) {
            return false;
        }

        return $this->evaluateChip(
            array_slice($flows, -self::WINDOW),
            $flows,
            $this->volumeByDate($series),
        );
    }

    /**
     * 籌碼規則不支援歷史回放。
     *
     * context 帶進來的是「當下」的籌碼序列，與 $n 指向的歷史時點無關。要正確
     * 回放必須把籌碼也切到該時點——chip_flows 確實有歷史可切，但那需要把
     * 時點資訊傳進 context，屬於另一階段的工作。在那之前回傳 false 而非
     * 沿用當下資料：用未來的籌碼去回測過去，是前視偏誤（look-ahead bias），
     * 會產出看起來很好但完全不可信的結果。
     */
    public function matchesAt(array $series, int $n, array $context = []): bool
    {
        return false;
    }

    /**
     * @param  list<ChipFlowData>  $window  採計窗口內的買賣超
     * @param  list<ChipFlowData>  $all  完整序列（連續天數需要看更早的資料）
     * @param  array<string, int|float>  $volumeByDate  同一檔的日期 → 成交量，供中性帶依籌碼日期取分母
     */
    abstract protected function evaluateChip(array $window, array $all, array $volumeByDate): bool;

    /** @param list<ChipFlowData> $flows */
    protected function sum(array $flows, string $field): int
    {
        $total = 0;

        foreach ($flows as $flow) {
            $total += $flow->{$field};
        }

        return $total;
    }

    /**
     * 外資最近一段連續同向的天數。淨額 0 視為中斷。
     *
     * 與 SignalEngine::foreignStreak() 同規則——兩處若各自實作，日後調整會分歧。
     *
     * @param  list<ChipFlowData>  $flows
     * @return array{0: int, 1: bool} [天數, 是否為買超]
     */
    protected function foreignStreak(array $flows): array
    {
        $last = $flows[count($flows) - 1]->foreignNet;

        if ($last === 0) {
            return [0, false];
        }

        $streak = 0;

        for ($i = count($flows) - 1; $i >= 0; $i--) {
            $net = $flows[$i]->foreignNet;

            if ($net === 0 || ($net > 0) !== ($last > 0)) {
                break;
            }

            $streak++;
        }

        return [$streak, $last > 0];
    }

    /**
     * 連續同向的那幾筆買賣超。
     *
     * 中性帶的分母要取這幾天的成交量，所以呼叫端需要的是「哪幾天」而不只是合計。
     * $streak < 1 時回空陣列而不是 array_slice($flows, -0)——後者會回整條序列。
     *
     * @param  list<ChipFlowData>  $flows
     * @return list<ChipFlowData>
     */
    protected function streakFlows(array $flows, int $streak): array
    {
        return $streak < 1 ? [] : array_slice($flows, -$streak);
    }

    /**
     * 連續同向那幾天的外資淨額合計。
     *
     * 中性帶以「整段」而非逐日判定：單日佔比約是 N 日的 1/N，逐日判會把整段
     * 顯著、但多數日子小額的連續段誤殺。與 SignalEngine::streakNet() 一致。
     *
     * @param  list<ChipFlowData>  $flows
     */
    protected function streakNet(array $flows, int $streak): int
    {
        $net = 0;

        foreach ($this->streakFlows($flows, $streak) as $flow) {
            $net += $flow->foreignNet;
        }

        return $net;
    }
}
