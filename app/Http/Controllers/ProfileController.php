<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $profile = $user->profile;

        return Inertia::render('Profile/Edit', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'approved_at' => $user->approved_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'preferences' => [
                'theme' => $profile?->theme ?? 'warm',
                'timezone' => $profile?->timezone ?? 'Asia/Taipei',
                'preferred_market' => $profile?->preferred_market ?? 'TW_US',
            ],
        ]);
    }

    /**
     * 更新姓名與電子郵件。
     *
     * email 的唯一性要排除自己，否則使用者只改姓名、email 原封不動送出時，
     * 會被自己既有的那筆撞成「已被使用」。
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->fill($data)->save();

        return redirect()->back()->with('success', '個人資料已更新。');
    }

    /**
     * 變更密碼。
     *
     * 必須驗證目前密碼：沒有這一步，任何拿到未鎖螢幕或既有 session 的人都能直接
     * 換掉密碼並把本人鎖在外面。
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'min:8', 'different:current_password'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => '目前密碼不正確。',
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // 換密碼後讓其他裝置的 session 失效，並保住目前這個，否則使用者會被自己
        // 的操作登出。
        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        return redirect()->back()->with('success', '密碼已更新，其他裝置的登入已失效。');
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(['warm', 'dark'])],
            'timezone' => ['required', 'string', 'timezone'],
            'preferred_market' => ['required', Rule::in(['TW', 'US', 'TW_US'])],
        ]);

        $user = $request->user();

        // profile 由 User::booted 的 created hook 建立，但舊資料或匯入的帳號
        // 可能沒有，這裡一併補上。
        $user->profile()->updateOrCreate([], $data);

        return redirect()->back()->with('success', '偏好設定已更新。');
    }
}
