<?php

namespace App\Support;

/**
 * Evaluasi ekspresi cron 5 field tanpa dependensi eksternal.
 *
 * Minute | Hour | Day-of-month | Month | Day-of-week
 *   *      setiap nilai
 *   a,b    daftar nilai
 *   a-b    rentang
 *   * / n  setiap n
 *
 * Kolom hari memakai perilaku cron standar: bila tanggal DAN nama hari
 * sama-sama dibatasi, keduanya di-OR-kan.
 */
final class CronExpression
{
    public static function matches(string $cron, \DateTimeInterface $moment): bool
    {
        $fields = preg_split('/\s+/', trim($cron)) ?: [];

        if (count($fields) !== 5) {
            return false;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        if (! self::fieldMatches($minute, (int) $moment->format('i'))) {
            return false;
        }

        if (! self::fieldMatches($hour, (int) $moment->format('G'))) {
            return false;
        }

        if (! self::fieldMatches($month, (int) $moment->format('n'))) {
            return false;
        }

        $domRestricted = ! str_contains($dayOfMonth, '*');
        $dowRestricted = ! str_contains($dayOfWeek, '*');

        $domMatch = self::fieldMatches($dayOfMonth, (int) $moment->format('j'));
        $dowMatch = self::fieldMatches($dayOfWeek, (int) $moment->format('w'));

        return ($domRestricted && $dowRestricted)
            ? ($domMatch || $dowMatch)
            : ($domMatch && $dowMatch);
    }

    private static function fieldMatches(string $field, int $value): bool
    {
        foreach (explode(',', $field) as $part) {
            $part = trim($part);

            if ($part === '*') {
                return true;
            }

            if (str_starts_with($part, '*/')) {
                $step = max(1, (int) substr($part, 2));

                if ($value % $step === 0) {
                    return true;
                }

                continue;
            }

            if (str_contains($part, '-')) {
                [$from, $to] = array_map('intval', explode('-', $part, 2));

                if ($value >= $from && $value <= $to) {
                    return true;
                }

                continue;
            }

            if ((int) $part === $value) {
                return true;
            }
        }

        return false;
    }
}
