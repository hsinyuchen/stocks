<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->withCount(['watchlists', 'stockAnalyses', 'newsAnalyses', 'stockChatTurns'])
            ->withExists('llmProviderSettings as has_llm')
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(fn ($inner) => $inner
                    ->where('email', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%"));
            })
            // 待審核的排最前面：那是唯一需要管理員動作的狀態，藏在第三頁等於沒做。
            ->orderByRaw('approved_at is not null')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'disabled_at' => $user->disabled_at?->toIso8601String(),
                'approved_at' => $user->approved_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'watchlists_count' => $user->watchlists_count,
                // AI 用量的總計：個股分析、新聞分析、個股問答都各是一次 LLM 呼叫。
                'analyses_count' => $user->stock_analyses_count
                    + $user->news_analyses_count
                    + $user->stock_chat_turns_count,
                'has_llm' => (bool) $user->has_llm,
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => ['q' => $q !== '' ? $q : null],
            // 搜尋或翻頁時也要看得到還有幾筆待辦，所以獨立統計而非數當頁。
            'pendingCount' => User::query()->whereNull('approved_at')->count(),
        ]);
    }

    /**
     * 核准一筆註冊申請。
     *
     * 記下核准者：出事時要能追出是誰放行的，這是這道關卡存在的意義之一。
     */
    public function approve(Request $request, User $user): RedirectResponse
    {
        if ($user->approved_at !== null) {
            return redirect()->back();
        }

        $user->forceFill([
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ])->save();

        Log::info('admin action', ['actor' => $request->user()->id, 'target' => $user->id, 'action' => 'approve']);

        return redirect()->back()->with('success', "已核准 {$user->email}。");
    }

    /**
     * 駁回申請＝刪除帳號。
     *
     * 不留「已駁回」狀態：那會讓 email 永久佔用 unique 索引，同一個人之後想重新
     * 申請就會撞到「此信箱已註冊」而完全無法自救。
     */
    public function reject(Request $request, User $user): RedirectResponse
    {
        if ($user->approved_at !== null) {
            return redirect()->back()->with('error', '此帳號已核准，請改用停用或刪除。');
        }

        Log::info('admin action', ['actor' => $request->user()->id, 'target' => $user->id, 'action' => 'reject', 'email' => $user->email]);

        $user->delete();

        return redirect()->back()->with('success', '已駁回並刪除該申請。');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        // 未給密碼時系統代產，flash 回畫面顯示一次，由 admin 轉交使用者。
        $generated = null;

        if (empty($data['password'])) {
            $generated = Str::password(16);
            $data['password'] = $generated;
        }

        $user = User::query()->create($data);

        // 管理員親手建立的帳號直接放行——再要求他去核准自己剛建的東西沒有意義。
        $user->forceFill(['approved_at' => now(), 'approved_by' => $request->user()->id])->save();

        Log::info('admin action', ['actor' => $request->user()->id, 'target' => $user->id, 'action' => 'create']);

        $redirect = redirect()->back();

        return $generated !== null
            ? $redirect->with('generated_password', $generated)
            : $redirect;
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        if ($error = $this->guardTarget($request, $user)) {
            return redirect()->back()->with('error', $error);
        }

        $user->disabled_at = now();
        $user->save();

        Log::info('admin action', ['actor' => $request->user()->id, 'target' => $user->id, 'action' => 'disable']);

        return redirect()->back();
    }

    public function enable(Request $request, User $user): RedirectResponse
    {
        $user->disabled_at = null;
        $user->save();

        Log::info('admin action', ['actor' => $request->user()->id, 'target' => $user->id, 'action' => 'enable']);

        return redirect()->back();
    }

    public function toggleRole(Request $request, User $user): RedirectResponse
    {
        // 升級不需 guard；降級（目標目前是 admin）需要。
        if ($user->is_admin && ($error = $this->guardTarget($request, $user))) {
            return redirect()->back()->with('error', $error);
        }

        $user->is_admin = ! $user->is_admin;
        $user->save();

        Log::info('admin action', ['actor' => $request->user()->id, 'target' => $user->id, 'action' => $user->is_admin ? 'promote' : 'demote']);

        return redirect()->back();
    }

    public function sendResetLink(Request $request, User $user): RedirectResponse
    {
        try {
            $status = Password::sendResetLink(['email' => $user->email]);
        } catch (\Throwable $exception) {
            report($exception);

            // SMTP 未配置或寄送失敗：回明確錯誤，不靜默失敗。
            return redirect()->back()->with('error', '郵件服務未設定或寄送失敗，請檢查 MAIL_* 環境設定。');
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return redirect()->back()->with('error', __($status));
        }

        Log::info('admin action', ['actor' => $request->user()->id, 'target' => $user->id, 'action' => 'reset-link']);

        return redirect()->back()->with('success', '重設信已寄出。');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($error = $this->guardTarget($request, $user)) {
            return redirect()->back()->with('error', $error);
        }

        Log::info('admin action', ['actor' => $request->user()->id, 'target' => $user->id, 'action' => 'delete', 'email' => $user->email]);

        $user->delete();

        return redirect()->back();
    }

    /**
     * 破壞性操作（停用/刪除/降級）的共用防線。
     * 回傳錯誤訊息字串；null 表示放行。
     */
    private function guardTarget(Request $request, User $target): ?string
    {
        if ($target->id === $request->user()->id) {
            return '不能對自己執行此操作。';
        }

        // 「有效」要同時看核准與停用：未核准的管理員登不進來，不能拿來充人數。
        $isLastActiveAdmin = $target->is_admin
            && $target->isActive()
            && User::query()->where('is_admin', true)
                ->whereNull('disabled_at')->whereNotNull('approved_at')->count() === 1;

        if ($isLastActiveAdmin) {
            return '這是最後一位有效管理員，不可停用、刪除或降級。';
        }

        return null;
    }
}
