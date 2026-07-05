<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
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
            ->paginate(25)
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

    public function store(Request $request)
    {
        abort(501);
    }

    public function disable(Request $request, User $user)
    {
        abort(501);
    }

    public function enable(Request $request, User $user)
    {
        abort(501);
    }

    public function toggleRole(Request $request, User $user)
    {
        abort(501);
    }

    public function sendResetLink(Request $request, User $user)
    {
        abort(501);
    }

    public function destroy(Request $request, User $user)
    {
        abort(501);
    }
}
