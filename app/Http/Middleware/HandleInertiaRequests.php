<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user()?->loadMissing('profile');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => (bool) $user->is_admin,
                    'profile' => [
                        'theme' => $user->profile?->theme ?? 'warm',
                        'locale' => $user->profile?->locale ?? 'zh',
                        'timezone' => $user->profile?->timezone ?? 'Asia/Taipei',
                        'preferred_market' => $user->profile?->preferred_market ?? 'TW_US',
                    ],
                ] : null,
            ],
            'flash' => [
                'error' => $request->session()->get('error'),
                'success' => $request->session()->get('success'),
                // InstrumentController 的四則成功訊息（新增／更新／刪除／匯入）用這個鍵，
                // Instruments.jsx 也在讀，先前漏了共享導致這些訊息永遠不會顯示。
                'status' => $request->session()->get('status'),
                'generated_password' => $request->session()->get('generated_password'),
                // 存檔成功但有需要提醒的事（例如掛了沒有行情的標的）。
                // 不能塞進 errors——Inertia 會判定表單驗證失敗，使用者會以為沒存進去。
                'warning' => $request->session()->get('warning'),
                // 題材規則試跑結果：不寫 DB，只透過 flash 帶回畫面。
                'previewResult' => $request->session()->get('previewResult'),
            ],
        ];
    }
}
