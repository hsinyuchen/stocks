<?php

return [
    /*
     * 主機探測：量測 cron 是否真的每分鐘觸發，以及一個長壽的背景 process 會不會
     * 被共享主機砍掉。
     *
     * 預設關閉。它本身也是一個存活數十秒的 process，長期開著等於在正式 worker
     * 之外再多佔一份資源；它的用途是「部署後跑一輪確認環境」，不是常態監控。
     */
    'enabled' => (bool) env('HOST_PROBE_ENABLED', false),

    /*
     * 每次取樣要存活幾秒。預設對齊 analysis.cron_worker.max_seconds——探測要模仿
     * 的就是真正的 queue:work，秒數不一樣就測不到同一條界線。
     */
    'seconds' => (int) env('HOST_PROBE_SECONDS', 55),

    /*
     * 心跳間隔（秒）。process 被 SIGKILL 時不會留下任何訊息，只能靠「最後一次
     * 心跳寫在第幾秒」回推它活了多久，所以這個值就是死亡時間的解析度。
     */
    'beat_seconds' => (int) env('HOST_PROBE_BEAT_SECONDS', 5),

    /*
     * 觀測窗（小時）。超過就自動停止取樣，不必記得回來關掉。
     * 2 小時＝120 個取樣點，足以分辨「每分鐘觸發」與「被降頻到 5 分鐘」。
     */
    'window_hours' => (float) env('HOST_PROBE_WINDOW_HOURS', 2),

    /*
     * 觀測資料落在 storage/logs/（已被該目錄的 .gitignore 涵蓋）。
     *
     * 刻意不寫 DB：共享主機的資料庫連線與配額本身就是受測資源，探測不該去污染
     * 它；而且 process 被強制中止時檔案已經 flush，未提交的 transaction 則會整段
     * 消失，正好丟失最關鍵的那一筆。
     */
    'path' => env('HOST_PROBE_PATH', storage_path('logs/host-probe.jsonl')),
];
