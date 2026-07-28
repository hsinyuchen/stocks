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

    protected function evaluateChip(array $window, array $all): bool
    {
        return $this->sum($window, 'foreignNet') > 0 && $this->sum($window, 'trustNet') > 0;
    }
}
