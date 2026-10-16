<?php

namespace App\Http\Middleware;

use App\Services\Guard\KillSwitch;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props yang dipakai semua halaman: siapa user dan apakah kill switch aktif.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                'name' => $user?->name,
                'email' => $user?->email,
                'role' => $user?->role?->value,
                'is_admin' => (bool) $user?->isAdmin(),
            ],

            'pause' => app(KillSwitch::class)->state(),
        ];
    }
}
