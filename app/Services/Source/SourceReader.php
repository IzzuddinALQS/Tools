<?php

namespace App\Services\Source;

use App\Services\Source\Exceptions\SourceUnavailableException;
use App\Services\Types\ColumnDefinition;

/**
 * SATU-SATUNYA pintu keluar tool ke Oracle (Keamanan 1).
 *
 * Tidak ada kelas lain di luar app/Services/Source yang boleh menyentuh
 * koneksi Oracle. Implementasi yang ada:
 *   - OracleReader  : Oracle asli (butuh ext-oci8, dipakai di server IT)
 *   - MockOracleReader : fixture, untuk pengembangan & test
 *
 * Kontrak:
 *  - hanya menerima statement SELECT / WITH yang sudah lolos SelectGuard
 *  - TIDAK PERNAH menerima INSERT/UPDATE/DELETE/DDL/PL-SQL
 *  - `tableColumns()` adalah satu-satunya cara membaca metadata kolom
 *  - tidak pernah menulis, tidak pernah membuat objek
 */
interface SourceReader
{
    /** Nilai driver: 'oracle' atau 'mock'. */
    public function name(): string;

    /**
     * Uji konektivitas (tombol "Tes Koneksi").
     *
     * @throws SourceUnavailableException
     */
    public function test(): void;

    /**
     * Jalankan satu SELECT dan stream hasilnya.
     *
     * @param  array<int|string,mixed>  $bindings
     * @param  int|null  $maxRows  batas baris keras (Keamanan 4)
     * @param  int|null  $timeoutSeconds  batas waktu (Keamanan 4)
     *
     * @throws SourceUnavailableException
     */
    public function stream(
        string $sql,
        array $bindings = [],
        ?int $maxRows = null,
        ?int $timeoutSeconds = null,
    ): SourceCursor;

    /**
     * Metadata kolom OTORITATIF dari katalog database.
     *
     * Untuk Oracle: ALL_TAB_COLUMNS. Nama dan TIPE diambil dari sini, bukan
     * ditebak dari isi data (Aturan 1).
     *
     * @return array<int,ColumnDefinition>|null  null bila tabel tidak ada
     *                                            atau tidak terlihat oleh user
     */
    public function tableColumns(string $owner, string $table): ?array;

    public function close(): void;
}
