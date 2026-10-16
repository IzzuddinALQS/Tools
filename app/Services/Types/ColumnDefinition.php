<?php

namespace App\Services\Types;

/**
 * Deskripsi satu kolom Oracle, diambil dari metadata (ALL_TAB_COLUMNS),
 * bukan ditebak dari isi data.
 */
final class ColumnDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $oracleType,
        public readonly ?int $dataLength = null,
        public readonly ?int $charLength = null,
        public readonly ?int $charUsed = null,
        public readonly ?int $dataPrecision = null,
        public readonly ?int $dataScale = null,
        public readonly ?int $position = null,
    ) {
    }

    /**
     * Bentukkan dari baris ALL_TAB_COLUMNS (uppercase) atau dari baris
     * fixture mock (uppercase).
     *
     * @param  array<string,mixed>  $row
     */
    public static function fromMetadataRow(array $row): self
    {
        return new self(
            name: (string) self::pick($row, ['COLUMN_NAME', 'column_name']),
            oracleType: strtoupper((string) self::pick($row, ['DATA_TYPE', 'data_type'])),
            dataLength: self::intOrNull(self::pick($row, ['DATA_LENGTH', 'data_length'])),
            charLength: self::intOrNull(self::pick($row, ['CHAR_LENGTH', 'char_length', 'DATA_LENGTH', 'data_length'])),
            charUsed: self::intOrNull(self::pick($row, ['CHAR_USED', 'char_used'])),
            dataPrecision: self::intOrNull(self::pick($row, ['DATA_PRECISION', 'data_precision'])),
            dataScale: self::intOrNull(self::pick($row, ['DATA_SCALE', 'data_scale'])),
            position: self::intOrNull(self::pick($row, ['COLUMN_ID', 'column_id', 'POSITION', 'position'])),
        );
    }

    /**
     * Lebar karakter untuk VARCHAR2/CHAR: Oracle menyimpan CHAR_USED='B'
     * (byte) atau 'C' (char, multibyte). Untuk varchar/char multibyte kita
     * butuh jumlah karakter, bukan jumlah byte.
     */
    public function effectiveCharLength(): ?int
    {
        return $this->charLength ?? $this->dataLength;
    }

    /**
     * @param  array<int,string>  $keys
     */
    private static function pick(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
