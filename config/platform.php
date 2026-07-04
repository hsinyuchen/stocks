<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | 公開主機部署時可關閉自助註冊（REGISTRATION_ENABLED=false），
    | 之後僅能由 admin 在管理頁建立帳號。
    |
    */

    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', true),

];
