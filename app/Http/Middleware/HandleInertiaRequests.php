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
                    'profile' => [
                        'theme' => $user->profile?->theme ?? 'warm',
                        'timezone' => $user->profile?->timezone ?? 'Asia/Taipei',
                        'preferred_market' => $user->profile?->preferred_market ?? 'TW_US',
                    ],
                ] : null,
            ],
            'flash' => [
                'error' => $request->session()->get('error'),
                'success' => $request->session()->get('success'),
                'generated_password' => $request->session()->get('generated_password'),
            ],
        ];
    }
}
