<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FinMind 免費層額度守門（FinMindGate）
    |--------------------------------------------------------------------------
    |
    | 免費層有每小時請求上限。撞限時開啟全站冷卻，冷卻期內所有 FinMind 呼叫
    | （行情、籌碼、融資、基本面、期貨）直接跳過走降級，不再對已耗盡的額度施壓。
    |
    | 升級為 FinMind 付費/贊助等級後可把 gate_enabled 設 false，或維持開啟當保險。
    |
    */

    'gate_enabled' => (bool) env('FINMIND_GATE_ENABLED', true),

    'cooldown_minutes' => (int) env('FINMIND_COOLDOWN_MINUTES', 10),
];
