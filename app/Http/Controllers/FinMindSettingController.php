<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 使用者自管的 FinMind API token。
 *
 * 一人一組（hasOne），故只有 store（新增/更新）與 destroy（清除退回全站 fallback），
 * 不需 LLM 那種多筆 + default 機制。token 經 FinMindSetting 的 encrypted cast 加密存，
 * 永不回傳明文（Settings 頁只顯示 has_token 布林）。
 */
class FinMindSettingController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        // updateOrCreate over hasOne：以 user_id 為條件，第二次儲存更新同一列。
        $request->user()->finmindSetting()->updateOrCreate(
            [],
            ['token_encrypted' => trim($data['token'])],
        );

        return redirect()->route('settings.index')->with('status', 'FinMind token 已更新。');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->finmindSetting()->delete();

        return redirect()->route('settings.index')->with('status', 'FinMind token 已清除，將改用全站預設額度。');
    }
}
