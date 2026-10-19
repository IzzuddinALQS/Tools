<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Services\Api\ApiTokenLookup;
use App\Support\TrustedProxy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi Bearer token untuk API Tahap 2.
 *
 * Token disimpan sebagai hash; yang dicocokkan hanya SHA-256nya.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Satu-satunya lookup untuk seluruh siklus request ini.
        $token = ApiTokenLookup::for($request);

        if ($token === null) {
            return $this->unauthorized(
                ApiTokenLookup::plainFrom($request) === null
                    ? 'Token tidak ditemukan. Kirim header: Authorization: Bearer <token>.'
                    : 'Token tidak dikenal.'
            );
        }

        if (! $token->isActive()) {
            return response()->json([
                'message' => 'Token sudah dicabut.',
                'revoked_at' => $token->revoked_at?->toIso8601String(),
            ], 403);
        }

        $request->attributes->set('api_token', $token);

        /*
         * Gagal tertutup saat IP klien tidak bisa dipastikan.
         *
         * Token ini membatasi IP, tapi request-nya datang lewat proxy yang
         * tidak dikonfigurasi tepercaya. Dalam keadaan itu $request->ip()
         * adalah IP proxy, bukan IP klien, sehingga penilaiannya salah sasaran.
         *
         * Diperiksa SEBELUM allowsIp(), karena kalau tidak, request-nya sudah
         * ditolak 403 dengan alasan yang menyesatkan sebelum pesan yang benar
         * ini sempat muncul.
         *
         * Menolak lebih aman daripada menebak: kalau request boleh lewat
         * berdasarkan IP proxy, seluruh pembatasan jadi tidak berlaku. Pesan
         * 409 ini juga membuat masalahnya kelihatan dan bisa diperbaiki lewat
         * TRUSTED_PROXIES.
         */
        $ambiguous = $this->clientIpIsUnreliable($request);

        if ($ambiguous !== null) {
            return response()->json([
                'message' => 'IP klien tidak bisa dipastikan, jadi pembatasan IP pada token tidak dapat dievaluasi. Set TRUSTED_PROXIES untuk aplikasi ini.',
                'reason' => $ambiguous,
            ], 409);
        }

        if (! $token->allowsIp($request->ip())) {
            return response()->json(['message' => 'Alamat IP tidak diizinkan untuk token ini.'], 403);
        }

        return $next($request);
    }

    /**
     * Kembalikan alasan kalau IP klien tidak bisa dipastikan, atau null kalau
     * tidak ada masalah.
     *
     * Hanya relevan untuk token yang punya daftar IP. Token tanpa daftar IP
     * tidak peduli IP berapa pun, jadi tidak ada yang perlu dipastikan.
     */
    private function clientIpIsUnreliable(Request $request): ?string
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        if ($token->allowed_ips === null || $token->allowed_ips === []) {
            return null;
        }

        if (TrustedProxy::configured()) {
            return null;
        }

        // Header proxy ada tapi tidak ada proxy yang dipercaya: IP yang dibaca
        // aplikasi pasti bukan IP klien.
        foreach (['X-Forwarded-For', 'X-Real-IP', 'Forwarded', 'CF-Connecting-IP'] as $header) {
            if ($request->headers->has($header)) {
                return 'proxy_header_present_but_untrusted:'.$header;
            }
        }

        return null;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['message' => $message], 401);
    }
}
