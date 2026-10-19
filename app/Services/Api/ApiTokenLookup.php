<?php

namespace App\Services\Api;

use App\Models\ApiToken;
use Illuminate\Http\Request;

/**
 * Pencarian token API, di-memoisasi per request.
 *
 * Tanpa ini, satu panggilan API membaca tabel api_tokens dua kali: sekali di
 * rate limiter untuk menghitung kuota, sekali lagi di middleware untuk
 * autentikasi. Dengan `CACHE_STORE=database` keduanya kena PostgreSQL.
 *
 * Hasilnya disimpan di atribut request, jadi selama satu siklus HTTP hanya ada
 * satu query. Request baru = cache baru, jadi tidak ada token yang "basi"
 * antar-request.
 */
final class ApiTokenLookup
{
    private const ATTRIBUTE = 'ods.resolved_token';

    /**
     * Ambil header bearer dari request, atau null.
     */
    public static function plainFrom(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            $value = trim(substr($header, 7));

            if ($value !== '') {
                return $value;
            }
        }

        // Beberapa klien di dalam intranet hanya bisa mengirim header kustom.
        $alt = $request->header('X-Api-Token');

        return is_string($alt) && $alt !== '' ? trim($alt) : null;
    }

    /**
     * Token untuk request ini, atau null kalau tidak ada / tidak dikenal.
     *
     * Dipanggil berkali-kali tetap hanya satu query.
     */
    public static function for(Request $request): ?ApiToken
    {
        if ($request->attributes->has(self::ATTRIBUTE)) {
            return $request->attributes->get(self::ATTRIBUTE);
        }

        $plain = self::plainFrom($request);

        $token = $plain === null ? null : ApiToken::findByPlainToken($plain);

        $request->attributes->set(self::ATTRIBUTE, $token);

        return $token;
    }
}