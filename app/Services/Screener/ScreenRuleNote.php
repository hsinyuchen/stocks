<?php

namespace App\Services\Screener;

/**
 * 呈現時必須一併說出的規則附註。
 *
 * 有些規則的名字會讓使用者推論出系統沒有驗證的事。最典型的是「早期社交套利」
 * ——SOP 2.3 列的 YouTube、X、Reddit、Threads、PTT、Dcard 與電商通路本平台
 * 一個都沒有接入，只有新聞熱度；不說的話「社交」兩個字自己會把使用者帶去
 * 一個不存在的資料來源。個股分析 prompt 與個股頁面板都強制輸出這類聲明，
 * 但選股器與警報這兩個消費端原本只拿得到 {@see ScreenRule::label()}，
 * 沒有任何更正機會（階段 4 最終審查的 I4）。
 *
 * **做成獨立介面而不是加進 `ScreenRule`**：加進去會強迫 25 條既有規則各補一個
 * 回 null 的方法，而其中 23 條本來就沒有要說的話。呼叫端用 `instanceof` 判斷，
 * 與 `BacktestService::unsupportedRules()` 判斷 `MarginRule` 是同一個模式。
 *
 * **回傳的是 i18n 鍵不是文字**：這些聲明是面向使用者的硬性揭露，英文介面漏掉
 * 會變成中文露出。`label()` 目前是單語（既有 25 條皆然），但那是名字；
 * 揭露內容不能比名字更差。
 */
interface ScreenRuleNote
{
    /**
     * 呈現此規則時必須一併顯示的 i18n 鍵，依顯示順序排列。
     *
     * **不得回空陣列**：實作了這個介面就代表有話要說，回空等於宣稱「沒有需要
     * 揭露的事」，那應該直接不實作這個介面。
     *
     * @return non-empty-list<string>
     */
    public function noteKeys(): array;
}
