<?php

namespace App\Services\Screener\Rules;

use App\Services\Screener\ScreenRule;

abstract class BaseRule implements ScreenRule
{
    protected const MIN_BARS = 30;

    public function matches(array $series): bool
    {
        $count = count($series['close'] ?? []);

        if ($count < self::MIN_BARS) {
            return false;
        }

        return $this->evaluate($series, $count - 1);
    }

    /** @param array<string, list<int|float|null>> $series */
    abstract protected function evaluate(array $series, int $n): bool;
}
