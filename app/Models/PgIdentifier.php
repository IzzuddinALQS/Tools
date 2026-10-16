<?php

namespace App\Models;

/**
 * Helper untuk quoted identifier PostgreSQL.
 *
 * Aturan "sama persis" (Aturan 1): nama dari Oracle dipakai apa adanya,
 * huruf besar, dan SELALU di-quote.
 *
 *   APPS.PO_HEADERS_ALL  ->  "APPS"."PO_HEADERS_ALL"
 *
 * Nama untuk PostgreSQL selalu di-escape dengan menggandakan `"` di dalamnya.
 */
final class PgIdentifier
{
    public static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    public static function qualify(?string $schema, string $table): string
    {
        return $schema !== null && $schema !== ''
            ? self::quote($schema).'.'.self::quote($table)
            : self::quote($table);
    }

    /**
     * Parse "APPS.PO_HEADERS_ALL" (tanpa quote) menjadi [schema, table].
     * Didukung juga bentuk 'APPS'.'PO_HEADERS_ALL' dan "APPS"."PO_HEADERS_ALL".
     */
    public static function parse(string $qualified): array
    {
        $parts = self::splitQualified($qualified);

        return match (count($parts)) {
            1 => [null, $parts[0]],
            2 => [$parts[0], $parts[1]],
            default => throw new \InvalidArgumentException(
                "Nama tabel tidak valid: {$qualified}"
            ),
        };
    }

    /**
     * @return array<int,string>
     */
    private static function splitQualified(string $qualified): array
    {
        $parts = [];
        $buffer = '';
        $inQuote = false;
        $length = strlen($qualified);

        for ($i = 0; $i < $length; $i++) {
            $char = $qualified[$i];

            if ($inQuote) {
                if ($char === '"') {
                    if ($i + 1 < $length && $qualified[$i + 1] === '"') {
                        $buffer .= '"';
                        $i++;
                        continue;
                    }
                    $inQuote = false;
                    continue;
                }
                $buffer .= $char;
                continue;
            }

            if ($char === '"') {
                $inQuote = true;
                continue;
            }

            if ($char === '.') {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }
}
