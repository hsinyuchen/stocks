<?php

return [
    /*
     * 沒有常駐 queue worker 時，改由 web request 在回應送出後順帶消化佇列。
     *
     * 部署環境若能跑常駐 worker 或每分鐘的 schedule:run，應該關掉這個開關並用
     * 正規的 worker——inline 模式受 PHP 的 max_execution_time 影響，長 prompt
     * 有被砍斷的風險，只是共享／受限主機下的折衷。
     */
    'inline_worker' => [
        'enabled' => (bool) env('ANALYSIS_INLINE_WORKER', true),

        /*
         * 單次 request 最多花在跑 job 的秒數。
         *
         * 回應早已送出，這段時間不影響使用者等待，但仍佔著一個 PHP-FPM worker，
         * 拉太長會吃掉併發能力。單筆分析實測 28～47 秒，因此預設值要能跑完一筆。
         */
        'max_seconds' => (int) env('ANALYSIS_INLINE_WORKER_SECONDS', 60),

        /* 單次 request 最多處理幾個 job，避免積壓時一個 request 扛下全部。 */
        'max_jobs' => (int) env('ANALYSIS_INLINE_WORKER_MAX_JOBS', 2),
    ],

    /*
     * LLM 呼叫逾時的下限（秒），會蓋過使用者在設定頁填的較小值。
     *
     * 存在的理由是本地 thinking model：它們把大部分時間花在推理，逾時設太短會
     * 在答案出來前就被切斷。但這個值同時決定「一次 request 最久佔用多久」，受限
     * 主機的 max_execution_time 可能低到蓋不住——實測 max_execution_time 只能設
     * 到 120 的環境就必須把它降下來，否則最壞情況會被 PHP 砍在半路。
     *
     * 只用雲端模型（gpt-5-mini 實測 28～50 秒）時，90 秒已經很寬鬆。
     */
    'llm_timeout_floor' => (int) env('ANALYSIS_LLM_TIMEOUT_FLOOR', 120),

    /*
     * 超過這個時間仍是 pending 的分析視為放棄：紀錄標成 failed，對應的過期 job
     * 一併作廢。沒有這道防線時，worker 不存在的環境會讓紀錄永遠停在「分析中」，
     * 前端也就永遠輪詢下去。
     *
     * 必須小於前端輪詢的放棄門檻（useAnalysisPolling 的 10 分鐘），這樣使用者
     * 看到的是「分析失敗＋原因」而不是「等太久所以不等了」。
     */
    'pending_timeout_minutes' => (int) env('ANALYSIS_PENDING_TIMEOUT_MINUTES', 8),

    /*
     * 回收檢查的節流間隔（秒）。每個 request 都掃一次沒有意義，超時本來就是
     * 分鐘級的事件。
     */
    'reaper_throttle_seconds' => (int) env('ANALYSIS_REAPER_THROTTLE_SECONDS', 60),
];
