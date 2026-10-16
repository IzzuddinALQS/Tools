<?php

namespace App\Services\Types;

/**
 * Pemetaan tipe Oracle -> PostgreSQL (Aturan 3).
 *
 * Prinsip:
 *  - presisi harus terjaga; jangan pernah Throw away digit
 *  - DATE Oracle punya komponen jam, jadi WAJIB jadi timestamp, bukan date
 *  - tipe yang tidak dikenal menjadi `text` + PERINGATAN, bukan diam-diam
 *    ditebak
 */
class OracleTypeMapper
{
    /** Batas VARCHAR PostgreSQL sebelum turun ke text. */
    private const VARCHAR_FALLBACK_LIMIT = 4000;

    public function map(ColumnDefinition $column): TypeMapping
    {
        $type = strtoupper(trim($column->oracleType));

        return match (true) {
            $type === 'NUMBER' => $this->mapNumber($column),
            $type === 'BINARY_FLOAT' => new TypeMapping(
                $type, 'real', TypeCategory::Float,
                warnings: ['BINARY_FLOAT hanya ~7 digit signifikan. Nilai yang butuh presisi lebih tinggi sebaiknya NUMBER atau BINARY_DOUBLE.'],
            ),
            $type === 'BINARY_DOUBLE' => new TypeMapping($type, 'double precision', TypeCategory::Float),

            $type === 'VARCHAR2' => $this->mapVarchar2($column),
            $type === 'NVARCHAR2' => $this->mapNvarchar2($column),
            $type === 'CHAR' => $this->mapChar($column),
            $type === 'NCHAR' => $this->mapNchar($column),

            in_array($type, ['CLOB', 'NCLOB', 'LONG', 'LONG VARCHAR'], true)
                => new TypeMapping($type, 'text', TypeCategory::Text),

            in_array($type, ['BLOB', 'RAW', 'LONG RAW'], true)
                => new TypeMapping($type, 'bytea', TypeCategory::Bytea),

            // Oracle DATE = tahun+bulan+hari+JAM+MENIT+DETIK.
            $type === 'DATE' => new TypeMapping(
                $type, 'timestamp(0)', TypeCategory::Timestamp,
                warnings: ['Oracle DATE memuat jam; dipetakan ke timestamp(0) agar jam tidak hilang.'],
            ),

            $type === 'TIMESTAMP' => new TypeMapping(
                $type, $this->timestampPrecision($column), TypeCategory::Timestamp,
            ),

            $this->isTimestampTz($type) => new TypeMapping(
                $type, 'timestamptz', TypeCategory::TimestampTz,
            ),

            $this->isTimestampLtz($type) => new TypeMapping(
                $type, 'timestamptz', TypeCategory::TimestampTz,
                warnings: ['TIMESTAMP WITH LOCAL TIME ZONE bergantung zona waktu sesi Oracle. Tool memaksa sesi ke UTC agar hasil konsisten.'],
            ),

            $type === 'INTERVAL DAY(9) TO SECOND(9)',
            $type === 'INTERVAL DAY TO SECOND',
            $type === 'INTERVAL DAY(9) TO SECOND',
                => new TypeMapping($type, 'interval', TypeCategory::Interval),

            $type === 'INTERVAL YEAR(9) TO MONTH',
            $type === 'INTERVAL YEAR TO MONTH',
                => new TypeMapping(
                    $type, 'interval', TypeCategory::Interval,
                    warnings: ['INTERVAL YEAR TO MONTH bukan durasi absolut; nilainya akan dibaca sebagai interval PostgreSQL.'],
                ),

            $type === 'BOOLEAN' => new TypeMapping(
                $type, 'boolean', TypeCategory::Boolean,
                warnings: ['BOOLEAN hanya tersedia di Oracle 23c ke atas.'],
            ),

            $type === 'JSON' => new TypeMapping(
                $type, 'text', TypeCategory::Text,
                fallback: true,
                warnings: ['JSON dipetakan ke text. Periksa apakah aplikasi membutuhkannya sebagai jsonb.'],
            ),

            $type === 'ROWID', $type === 'UROWID' => new TypeMapping(
                $type, 'text', TypeCategory::Text,
                fallback: true,
                warnings: ["ROWID tidak punya padanan PostgreSQL; disimpan sebagai text."],
            ),

            $type === 'XMLTYPE' => new TypeMapping(
                $type, 'text', TypeCategory::Text,
                fallback: true,
                warnings: ['XMLTYPE disimpan sebagai text. Akses XML akan perlu reinterpretasi di aplikasi.'],
            ),

            default => $this->mapUnknown($column, $type),
        };
    }

    /**
     * NUMBER(p,s) -> numeric(p,s), NUMBER -> numeric (Aturan 3).
     */
    private function mapNumber(ColumnDefinition $column): TypeMapping
    {
        $precision = $column->dataPrecision;
        $scale = $column->dataScale;

        // NUMBER tanpa presisi
        if ($precision === null || $precision === 0) {
            return new TypeMapping(
                'NUMBER', 'numeric', TypeCategory::Numeric,
                warnings: ['NUMBER tanpa presisi; presisi tidak dijamin. Sebaiknya specify NUMBER(p,s) di query bila butuh presisi pasti.'],
            );
        }

        // Skala negatif = pembulatan ke ribuan (NUMBER(5,-2))
        if ($scale !== null && $scale < 0) {
            return new TypeMapping(
                'NUMBER', "numeric({$precision})", TypeCategory::Numeric,
                warnings: ["NUMBER(p,{$scale}) memakai skala negatif: nilai dibulatkan ke ribuan saat disimpan di Oracle. Disimpan sebagai numeric({$precision})."],
            );
        }

        $scale ??= 0;

        return new TypeMapping(
            'NUMBER', "numeric({$precision},{$scale})", TypeCategory::Numeric
        );
    }

    private function mapVarchar2(ColumnDefinition $column): TypeMapping
    {
        $length = $column->effectiveCharLength();

        if ($length === null || $length > self::VARCHAR_FALLBACK_LIMIT) {
            return new TypeMapping(
                'VARCHAR2', 'text', TypeCategory::Text,
                fallback: true,
                warnings: ['Panjang VARCHAR2 tidak terbaca atau melebihi batas; disimpan sebagai text.'],
            );
        }

        return new TypeMapping('VARCHAR2', "varchar({$length})", TypeCategory::Text);
    }

    private function mapNvarchar2(ColumnDefinition $column): TypeMapping
    {
        $length = $column->effectiveCharLength();

        if ($length === null || $length > self::VARCHAR_FALLBACK_LIMIT) {
            return new TypeMapping(
                'NVARCHAR2', 'text', TypeCategory::Text,
                fallback: true,
                warnings: ['Panjang NVARCHAR2 tidak terbaca; disimpan sebagai text.'],
            );
        }

        return new TypeMapping(
            'NVARCHAR2', "varchar({$length})", TypeCategory::Text,
            warnings: ['NVARCHAR2 memerlukan charset Unicode di sisi Oracle (NLS_CHARACTERSET) agar karakter multibyte tidak rusak.'],
        );
    }

    private function mapChar(ColumnDefinition $column): TypeMapping
    {
        $length = $column->effectiveCharLength();

        if ($length === null || $length < 1) {
            return new TypeMapping(
                'CHAR', 'char(1)', TypeCategory::Text,
                fallback: true,
                warnings: ['Panjang CHAR tidak terbaca; disimpan sebagai char(1).'],
            );
        }

        return new TypeMapping(
            'CHAR', "char({$length})", TypeCategory::Text,
            warnings: ['Oracle CHAR menyimpan nilai blank-padded. PostgreSQL char(n) juga, tetapi perbandingannya mengabaikan spasi akhir.'],
        );
    }

    private function mapNchar(ColumnDefinition $column): TypeMapping
    {
        $length = max(1, (int) $column->effectiveCharLength());

        return new TypeMapping(
            'NCHAR', "char({$length})", TypeCategory::Text,
            warnings: ['NCHAR memerlukan charset Unicode di sisi Oracle.'],
        );
    }

    /**
     * Tipe Oracle lain (SDO_*, MDSYS.*, ANYDATA, UDT, dll).
     */
    private function mapUnknown(ColumnDefinition $column, string $type): TypeMapping
    {
        return new TypeMapping(
            $type,
            'text',
            TypeCategory::Text,
            fallback: true,
            warnings: ["Tipe Oracle \"{$type}\" pada kolom {$column->name} tidak dikenal; disimpan sebagai text."],
        );
    }

    private function timestampPrecision(ColumnDefinition $column): string
    {
        $precision = $column->dataPrecision;

        if ($precision === null || $precision < 0 || $precision > 6) {
            return 'timestamp';
        }

        return "timestamp({$precision})";
    }

    private function isTimestampTz(string $type): bool
    {
        return $type === 'TIMESTAMP WITH TIME ZONE'
            || (bool) preg_match('/^TIMESTAMP\(\d\)\s+WITH TIME ZONE$/', $type);
    }

    private function isTimestampLtz(string $type): bool
    {
        return $type === 'TIMESTAMP WITH LOCAL TIME ZONE'
            || (bool) preg_match('/^TIMESTAMP\(\d\)\s+WITH LOCAL TIME ZONE$/', $type);
    }
}
