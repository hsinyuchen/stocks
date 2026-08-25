<?php

namespace App\Enums;

use App\Services\News\TransmissionMapper;

/**
 * 傳導表對某個 sector 標註的方向。
 *
 * **這是傳導表的標註，不是對個股後續走勢的預測**，呈現層的必要說明必須寫明。
 *
 * 取自 config 的宣告值，**不取 {@see TransmissionMapper} 的新聞極性**：那個機制
 * （direction_cues 判 forward／reverse 再翻轉）是為「單一則新聞」設計的，
 * 而題材頁面對的是整個視窗。要用極性就得把數百則新聞聚合成一個結論，
 * 那是一個沒有依據的新推論。且實測只有 memory_cycle 與 twd_fx 有 cues，
 * 這兩個題材近 30 日的新聞絕大多數判為 unknown（159:9 與 121:19），
 * 用極性會讓方向幾乎全空，資訊反而更少。
 */
enum TopicDirection: string
{
    case Benefits = 'benefits';
    case Harmed = 'harmed';

    /**
     * config 的 `direction` 只有三個合法值，但這裡對任何非預期字串一律回 null。
     *
     * 回 null 而不是丟例外或預設成某一邊：config 打錯字時，「不知道方向」是
     * 安全的降級，「猜一個方向」會對使用者宣稱一件系統其實不知道的事。
     */
    public static function fromDeclared(string $declared): ?self
    {
        return match ($declared) {
            'positive' => self::Benefits,
            'negative' => self::Harmed,
            default => null,
        };
    }
}
