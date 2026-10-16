<?php

namespace App\Services\Types;

/**
 * Kategori logis dari sebuah tipe PostgreSQL.
 *
 * Fungsi ini adalah SATU-SATUNYA sumber kebenaran tentang bagaimana nilai
 * harus dikanonikalisasi sebelum di-hash. Dipakai oleh Canonicalizer supaya
 * sisi Oracle (nilai dari oci8) dan sisi PostgreSQL (nilai dari pdo_pgsql)
 * menghasilkan byte yang sama.
 */
final class PgType
{
    public static function categoryOf(string $pgType): TypeCategory
    {
        $type = strtolower(trim($pgType));

        /*
         * PostgreSQL menuliskan tipe dalam dua bentuk:
         *   - disusun oleh pemetaan tipe kita : "timestamp(6)", "char(1)"
         *   - hasil format_type() di katalog  : "timestamp(6) without time zone",
         *                                        "character varying(30)",
         *                                        "character(1)"
         *
         * Kalau bentuk kedua tidak dikenali, timestamp akan dianggap TEKS dan
         * hash sisi Oracle vs sisi PostgreSQL akan berbeda padahal isinya sama.
         * Karena itu bentuk PostgreSQL harus dinormalkan lebih dulu.
         */
        $suffix = '';

        if (preg_match('/^(.*?)\s+(with|without)\s+time\s+zone$/', $type, $matches) === 1) {
            $type = trim($matches[1]);
            $suffix = $matches[2] === 'with' ? 'tz' : '';
        }

        // numeric(p,s) / decimal(p,s)
        if (preg_match('/^(numeric|decimal)\s*(\(\s*\d+\s*(,\s*-?\d+\s*)?\))?$/', $type) === 1) {
            return TypeCategory::Numeric;
        }

        if (in_array($type, ['real', 'double precision', 'float4', 'float8', 'float'], true)) {
            return TypeCategory::Float;
        }

        if (in_array($type, ['smallint', 'integer', 'int', 'int2', 'int4', 'int8', 'bigint', 'serial', 'bigserial'], true)) {
            return TypeCategory::Integer;
        }

        if (in_array($type, ['boolean', 'bool'], true)) {
            return TypeCategory::Boolean;
        }

        if ($type === 'bytea') {
            return TypeCategory::Bytea;
        }

        if ($type === 'timestamp' || preg_match('/^timestamp\s*\(\d+\)$/', $type) === 1) {
            return $suffix === 'tz' ? TypeCategory::TimestampTz : TypeCategory::Timestamp;
        }

        // Alias PostgreSQL untuk timestamp dengan zona waktu.
        if ($type === 'timestamptz') {
            return TypeCategory::TimestampTz;
        }

        if ($type === 'interval' || str_starts_with($type, 'interval ')) {
            return TypeCategory::Interval;
        }

        return TypeCategory::Text;
    }

    /**
     * Apakah tipe ini menyimpan data biner yang harus di-encode base64.
     */
    public static function isBytea(string $pgType): bool
    {
        return self::categoryOf($pgType) === TypeCategory::Bytea;
    }

    /**
     * Apakah ini tipe karakter (text / varchar / char).
     */
    public static function isCharacter(string $pgType): bool
    {
        return self::categoryOf($pgType) === TypeCategory::Text;
    }

    /**
     * Apakah kolom ini blank-padded: `char(n)` atau `character(n)`.
     *
     * Bentuk `character(n)` muncul dari katalog PostgreSQL, sedangkan
     * `char(n)` disusun oleh pemetaan tipe Oracle.
     */
    public static function isBlankPadded(string $pgType): bool
    {
        return preg_match('/^(?:char|character)\s*\(\d+\)/', strtolower(trim($pgType))) === 1;
    }
}
