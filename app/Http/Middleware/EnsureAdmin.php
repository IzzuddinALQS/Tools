<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hanya admin yang boleh membuka halaman Driver dan mengubah dataset
 * (Keamanan 3). Peran dicek di sini, bukan disembunyikan di view.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! $user->isAdmin()) {
            abort(403, 'Hanya admin yang boleh mengubah driver, koneksi, dan dataset.');
        }

        return $next($request);
    }
}
