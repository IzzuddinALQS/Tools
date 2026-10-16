<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
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
        $plain = $this->extractToken($request);

        if ($plain === null) {
            return $this->unauthorized('Token tidak ditemukan. Kirim header: Authorization: Bearer <token>.');
        }

        $token = ApiToken::findByPlainToken($plain);

        if ($token === null) {
            return $this->unauthorized('Token tidak dikenal.');
        }

        if (! $token->isActive()) {
            return response()->json([
                'message' => 'Token sudah dicabut.',
                'revoked_at' => $token->revoked_at?->toIso8601String(),
            ], 403);
        }

        if (! $token->allowsIp($request->ip())) {
            return response()->json(['message' => 'Alamat IP tidak diizinkan untuk token ini.'], 403);
        }

        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            $value = trim(substr($header, 7));

            return $value === '' ? null : $value;
        }

        // Beberapa klien (mis. integrasi di dalam intranet) hanya bisa
        // mengirim header kustom.
        $alt = $request->header('X-Api-Token');

        return is_string($alt) && $alt !== '' ? $alt : null;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['message' => $message], 401);
    }
}
