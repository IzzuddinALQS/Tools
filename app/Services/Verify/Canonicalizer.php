<?php

namespace App\Services\Verify;

use App\Services\Types\TypeCategory;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Kanonikalisasi nilai agar sisi Oracle dan sisi PostgreSQL menghasilkan byte
 * yang PERSIS sama sebelum di-hash.
 *
 * Ini adalah jantung aturan "sama persis". Kalau bentuk kanoniknya berbeda
 * sedikit saja antara oci8 dan pdo_pgsql, verifikasi hash akan selalu gagal
 * although datanya benar.
 *
 * Prinsip (Aturan 4):
 *   - nilai disalin apa adanya: TANPA trim, TANPA pembulatan, NULL tetap NULL
 *   - tidak ada konversi zona waktu: kedua sisi dipaksa ke UTC lebih dulu
 *   - perbandingan dilakukan pada bentuk TEKS yang dinormalisasi, bukan float
 *
 * Bentuk kanonik:
 *   numeric            : desimal ternormalisasi, contoh 123.4500 -> "123.45"
 *   real/double        : sprintf('%.15E'), contoh 1.5 -> "1.500000000000000E+00"
 *   integer            : digit desimal
 *   boolean            : "1" / "0"
 *   bytea              : base64
 *   timestamp          : "Y-m-d\TH:i:s.u"  (tanpa offset)
 *   timestamptz        : "Y-m-d\TH:i:s.u+00:00" (sudah dinormalisasi ke UTC)
 *   text/varchar/char  : byte UTF-8 apa adanya
 */
final class Canonicalizer
{
    private const MICROSECOND_PRECISION = 6;

    /**
     * @param  array<int,?string>  $values  Nilai yang SUDAH dikanonikalisasi
     * @return string|null  null berarti SQL NULL
     */
    public static function canonicalize(mixed $value, string $pgType): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::byCategory($value, \App\Services\Types\PgType::categoryOf($pgType));
    }

    public static function byCategory(mixed $value, TypeCategory $category): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($category) {
            TypeCategory::Numeric => self::numeric($value),
            TypeCategory::Float => self::float($value),
            TypeCategory::Integer => self::integer($value),
            TypeCategory::Boolean => self::boolean($value),
            TypeCategory::Bytea => self::bytea($value),
            TypeCategory::Timestamp => self::timestamp($value, withOffset: false),
            TypeCategory::TimestampTz => self::timestamp($value, withOffset: true),
            TypeCategory::Interval => self::text($value),
            TypeCategory::Text => self::text($value),
        };
    }

    /**
     * Desimal ternormalisasi.
     *
     * Oracle NUMBER(10,4) bernilai 123.45 -> oci8 "123.45"
     * PostgreSQL numeric(10,4)       -> pdo_pgsql "123.4500"
     * Keduanya harus menjadi "123.45".
     *
     * Angka nol menjadi "0" (bukan "-0", bukan "0.0").
     */
    public static function numeric(mixed $value): string
    {
        if (is_float($value)) {
            $value = self::floatToPlainDecimal($value);

            if ($value === null) {
                return 'NaN';
            }
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }

        $raw = trim($value);

        if ($raw === '') {
            return '0';
        }

        // Pisahkan tanda, mantissa, dan eksponen.
        $sign = '';

        if ($raw[0] === '-' || $raw[0] === '+') {
            $sign = $raw[0] === '-' ? '-' : '';
            $raw = substr($raw, 1);
        }

        $exponent = 0;

        if (preg_match('/^([0-9]*\.?[0-9]*)[eE]([+-]?[0-9]+)$/', $raw, $matches) === 1) {
            $raw = $matches[1];
            $exponent = (int) $matches[2];
        }

        $pointPosition = strpos($raw, '.');

        if ($pointPosition === false) {
            $integerPart = $raw;
            $fractionPart = '';
        } else {
            $integerPart = substr($raw, 0, $pointPosition);
            $fractionPart = substr($raw, $pointPosition + 1);
        }

        $integerPart = preg_replace('/[^0-9]/', '', $integerPart) ?? '';
        $fractionPart = preg_replace('/[^0-9]/', '', $fractionPart) ?? '';

        // Geser titik desimal menurut eksponen.
        $point = strlen($integerPart) + $exponent;

        $digits = $integerPart.$fractionPart;

        if ($digits === '') {
            return '0';
        }

        $integerDigits = '';

        if ($point <= 0) {
            $integerDigits = '0';
            $fractionDigits = str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $integerDigits = $digits.str_repeat('0', $point - strlen($digits));
            $fractionDigits = '';
        } else {
            $integerDigits = substr($digits, 0, $point);
            $fractionDigits = substr($digits, $point);
        }

        // Buang nol di depan pada bagian bulat, dan nol di belakang pada
        // bagian pecahan. Nilai tidak berubah, hanya bentuknya.
        $integerDigits = ltrim($integerDigits, '0');

        if ($integerDigits === '') {
            $integerDigits = '0';
        }

        $fractionDigits = rtrim($fractionDigits, '0');

        // Semua digit nol?
        if ($integerDigits === '0' && $fractionDigits === '') {
            return '0';
        }

        $result = $integerDigits;

        if ($fractionDigits !== '') {
            $result .= '.'.$fractionDigits;
        }

        return $sign.$result;
    }

    /**
     * Float IEEE-754.
     *
     * Kedua sisi memegang float yang sama persis, jadi cukup diformat dengan
     * 16 digit desimal eksponensial — cukup untuk presisi penuh double tanpa
     * ada informasi yang hilang.
     */
    public static function float(mixed $value): string
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        $float = is_numeric($value) ? (float) $value : NAN;

        if (is_nan($float)) {
            return 'NaN';
        }

        if (is_infinite($float)) {
            return $float > 0 ? 'Infinity' : '-Infinity';
        }

        return self::normalizeExponent(sprintf('%.15E', $float));
    }

    /**
     * PHP tidak zero-pad eksponen: 1.5 menjadi "1.500000000000000E+0".
     * Format itu sah, tapi rapikan jadi dua digit supaya digest stabil dan
     * mudah dibaca saat dipakai membandingkan hasil.
     */
    private static function normalizeExponent(string $formatted): string
    {
        // ${1} wajib ditulis eksplisit: "$10" akan dibaca sebagai group 10.
        return (string) preg_replace('/E([+-])(\d)$/', 'E${1}0${2}', $formatted);
    }

    public static function integer(mixed $value): string
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_numeric($value)) {
            return '0';
        }

        return (string) (int) $value;
    }

    public static function boolean(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 't', 'true', 'y', 'yes'], true) ? '1' : '0';
    }

    public static function bytea(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return base64_encode((string) $value);
    }

    /**
     * Timestamp.
     *
     * `timestamp` (tanpa zona) tidak punya zona: diasumsikan UTC di kedua
     * sisi, karena tool memaksa sesi Oracle dan PG ke UTC.
     *
     * `timestamptz` selalu dikonversi ke UTC sebelum diformat.
     */
    public static function timestamp(mixed $value, bool $withOffset): string
    {
        $date = self::parseDate((string) $value);

        if ($date === null) {
            return (string) $value;
        }

        $utc = (new DateTimeImmutable('@'.$date->format('U.u')))->setTimezone(new DateTimeZone('UTC'));

        $base = $utc->format('Y-m-d').'T'.$utc->format('H:i:s')
            .'.'.str_pad($utc->format('u'), self::MICROSECOND_PRECISION, '0', STR_PAD_LEFT);

        return $withOffset ? $base.'+00:00' : $base;    }

    /**
     * Teks: apa adanya, TANPA trim (Aturan 4).
     */
    public static function text(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return (string) $value;
    }

    /**
     * Parse tanggal dari berbagai representasi yang mungkin muncul:
     *   Oracle via NLS  : "2024-03-15 08:30:00", "2024-03-15 08:30:00.123456"
     *   Oracle TZ       : "...+00:00", "... +00:00"
     *   PostgreSQL::text: "2024-03-15 08:30:00", "2024-03-15 08:30:00+00"
     */
    private static function parseDate(string $value): ?DateTimeInterface
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Samakan pemisah timezone "+00" -> "+00:00".
        $normalized = preg_replace('/([+-]\d{2})$/', '$1:00', $value);

        // Buang sufiks TZH/TZM/TZR yang mungkin dari NLS_TIMESTAMP_TZ_FORMAT.
        $normalized = preg_replace('/\s*[+-]\d{2}:\d{2}(\s+[A-Za-z_+\-\d\/:]+)$/', '$1', $normalized) ?? $normalized;

        foreach ([DateTimeInterface::RFC3339_EXTENDED, 'Y-m-d H:i:s.u', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $normalized, new DateTimeZone('UTC'));

            if ($date !== false) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($normalized, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ubah float PHP menjadi string desimal biasa (tanpa ekspon) supaya bisa
     * dilewatkan ke normalisasi numeric.
     */
    private static function floatToPlainDecimal(float $value): ?string
    {
        if (! is_finite($value)) {
            return null;
        }

        return rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.') ?: '0';
    }
}
