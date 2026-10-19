<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Profil admin: ganti password.
 *
 * Password admin sebelumnya tidak bisa diubah dari mana pun, jadi `ods12345`
 * praktis permanen selama tool ini hidup — meski bocor lewat dump `sessions`
 * atau lewat siapa pun yang punya akses shell.
 */
class ProfileController extends Controller
{
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed', 'different:current_password'],
        ], [
            'password.min' => 'Password baru minimal 12 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.different' => 'Password baru harus berbeda dari password lama.',
        ]);

        // Password lama dicek lewat Hash, bukan Auth::attempt, supaya
        // percobaan salah tidak ikut terhitung oleh throttle login.
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Password lama salah.',
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        /*
         * Sesi lain dibatalkan supaya password yang bocor tidak tetap
         * berguna. Sesi yang sedang dipakai sengaja dipertahankan supaya
         * admin tidak terkunci keluar dari tab yang sedang dibuka.
         */
        $this->revokeOtherSessions($request, (int) $user->id);

        return back()->with('success', 'Password diganti. Sesi lain sudah dikeluarkan.');
    }

    /**
     * Buang seluruh sesi milik user ini kecuali yang sedang dipakai.
     */
    private function revokeOtherSessions(Request $request, int $userId): void
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
            // Hanya driver database yang bisa dihapus dari sisi server.
            // Untuk driver lain, rotasi ID sesi sudah cukup untuk pemakaian
            // ulang token CSRF.
            $request->session()->regenerate();

            return;
        }

        $current = $request->session()->getId();

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->when($current !== null, fn ($query) => $query->where('id', '!=', $current))
            ->delete();
    }
}