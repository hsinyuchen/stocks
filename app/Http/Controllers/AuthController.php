<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function showLogin(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/Login', [
            'registrationEnabled' => (bool) config('platform.registration_enabled', true),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, (bool) $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        // 待審核與已停用要分開講：前者是等人處理，後者是被收回權限，
        // 使用者該採取的行動完全不同。
        if ($request->user()->isPendingApproval()) {
            $this->forget($request);

            throw ValidationException::withMessages([
                'email' => '此帳號尚待管理員審核，核准後才能登入。',
            ]);
        }

        if ($request->user()->disabled_at !== null) {
            $this->forget($request);

            throw ValidationException::withMessages([
                'email' => '此帳號已停用。',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function register(Request $request): RedirectResponse
    {
        abort_unless((bool) config('platform.registration_enabled', true), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        // 自助註冊只是「提出申請」：approved_at 留空，等管理員在後台放行。
        // 不呼叫 Auth::login——未核准的帳號連一次 session 都不該拿到。
        User::query()->create($data);

        Log::info('registration requested', ['email' => $data['email']]);

        return redirect()->route('login')
            ->with('success', '申請已送出，待管理員審核通過後即可登入。');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** 登出並丟掉整個 session，避免任何殘留狀態被下一個請求沿用。 */
    private function forget(Request $request): void
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
