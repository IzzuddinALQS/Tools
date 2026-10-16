<?php

namespace App\Support;

/**
 * Daftar proxy yang boleh dipercaya saat aplikasi berjalan di belakang reverse
 * proxy atau load balancer.
 *
 * Sumbernya `TRUSTED_PROXIES`, daftar IP dipisah koma. Kosong berarti tidak
 * ada proxy yang dipercaya, dan itu nilai default yang benar.
 *
 * Kenapa tidak boleh pakai '*': begitu '*' aktif, siapa pun bisa memalsukan
 * header `X-Forwarded-For`, sehingga nilai IP yang dilihat aplikasi bisa
 * dikendalikan oleh pemanggil. Fitur pembatasan IP pada token API lalu
 * kehilangan seluruh artinya.
 */
final class TrustedProxy
{
    public const ENV_KEY = 'TRUSTED_PROXIES';

    /**
     * @return array<int,string>|null  null berarti tidak ada proxy tepercaya
     */
    public static function list(): ?array
    {
        $raw = (string) env(self::ENV_KEY, '');

        if (trim($raw) === '') {
            return null;
        }

        $proxies = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,]+/', trim($raw)) ?: []
        )));

        return $proxies === [] ? null : $proxies;
    }

    /**
     * Apakah ada proxy yang dikonfigurasi sama sekali.
     */
    public static function configured(): bool
    {
        return self::list() !== null;
    }
}