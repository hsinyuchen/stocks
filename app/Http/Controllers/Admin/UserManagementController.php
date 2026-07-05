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
        return Inertia::render('Admin/Users', [
            'users' => [],
            'filters' => ['q' => null],
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
