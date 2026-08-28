<?php

namespace App\Enums;

/**
 * 傳導表對某個 sector 標註的方向。
 *
 * 與 {@see TopicDirection} 不同：那個是候選標的的 benefits／harmed 分類，
 * 這個是策展人在傳導表上宣告的原始方向值，會被 TransmissionMapper 的
 * 新聞極性翻轉或降為中性。
 */
enum SectorDirection: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Neutral = 'neutral';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
