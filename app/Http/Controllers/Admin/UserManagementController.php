<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            ->withCount(['watchlists', 'stockAnalyses', 'newsAnalyses'])
            ->withExists('llmProviderSettings as has_llm')
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(fn ($inner) => $inner
                    ->where('email', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%"));
            })
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'disabled_at' => $user->disabled_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'watchlists_count' => $user->watchlists_count,
                'analyses_count' => $user->stock_analyses_count + $user->news_analyses_count,
                'has_llm' => (bool) $user->has_llm,
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => ['q' => $q !== '' ? $q : null],
        ]);
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

    public function sendResetLink(Request $request, User $user)
    {
        abort(501);
    }

    public function destroy(Request $request, User $user)
    {
        abort(501);
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

        $isLastActiveAdmin = $target->is_admin
            && $target->disabled_at === null
            && User::query()->where('is_admin', true)->whereNull('disabled_at')->count() === 1;

        if ($isLastActiveAdmin) {
            return '這是最後一位有效管理員，不可停用、刪除或降級。';
        }

        return null;
    }
}
