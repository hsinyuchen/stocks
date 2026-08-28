<?php

namespace App\Contracts;

/**
 * 題材傳導規則的來源。
 *
 * 回傳形狀與搬遷前的 `config('news.transmission')` **完全相同**：
 * `[{key, label, when: {keywords, domains}, chain, sectors: [{name, direction, symbols}], direction_cues?}]`。
 * 刻意不引入 DTO——形狀相同才能讓三個既有消費端各只改一行。
 *
 * `direction_cues` 只在有值時出現。缺鍵與 NULL 在 TransmissionMapper 裡語意相同
 * （維持宣告方向），但 `{"forward":[],"reverse":[]}` 會讓整組降為中性，因此實作
 * 必須輸出「缺鍵」而不是空物件。
 *
 * locale 一律由呼叫端顯式傳入。**不得改讀 `app()->getLocale()`**：本專案沒有任何
 * `setLocale()` 呼叫，`APP_LOCALE=en`，該值恆為 en；使用者語系實際存在
 * `user->profile->locale`。
 */
interface TransmissionRuleProvider
{
    /**
     * @param  string  $locale  'zh'|'en'
     * @return list<array<string, mixed>>
     */
    public function rules(string $locale = 'zh'): array;
}
