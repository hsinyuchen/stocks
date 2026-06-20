<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user()?->loadMissing('profile');

        return Inertia::render('Dashboard', [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'profile' => [
                        'theme' => $user->profile?->theme ?? 'warm',
                        'timezone' => $user->profile?->timezone ?? 'Asia/Taipei',
                        'preferred_market' => $user->profile?->preferred_market ?? 'TW_US',
                    ],
                ] : null,
            ],
        ]);
    }
}
