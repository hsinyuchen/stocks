<?php

namespace App\Services\Screener\Rules;

/**
 * 外資與投信近五日同步買超。
 *
 * 兩者同向的參考價值高於單一法人：外資多為被動配置與趨勢跟隨，投信受績效
 * 考核而偏中短線，兩者立場不同卻同向時，訊號較不易只是單一資金的調節。
 */
class InstitutionalAccumulation extends ChipRule
{
    public function key(): string
    {
        return 'institutional_accumulation';
    }

    public function label(): string
    {
        return '外資與投信同步買超';
    }

    /**
     * 兩條腿都要過中性帶，不是兩邊都大於 0。
     *
     * 只看正負的話「外資買 1 股、投信買 1 股」也算同步買超，而呈現層會把它講成
     * 法人同步進場。門檻與分母見 ChipNeutralBand。
     */
    protected function evaluateChip(array $window, array $all, array $volumeByDate): bool
    {
        $foreignNet = $this->sum($window, 'foreignNet');
        $trustNet = $this->sum($window, 'trustNet');

        return $foreignNet > 0
            && $trustNet > 0
            && $this->isSignificantNet($foreignNet, $volumeByDate, $window)
            && $this->isSignificantNet($trustNet, $volumeByDate, $window);
    }
}
