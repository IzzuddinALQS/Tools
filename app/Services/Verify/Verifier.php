<?php

namespace App\Services\Verify;

use App\Services\Sync\TableWriter;
use App\Services\Types\PgType;
use App\Services\Types\TypeCategory;
use Illuminate\Database\Connection;

/**
 * Verifikasi hash isi (Aturan 5).
 *
 * Dua arah:
 *   - Oracle -> PG A : hash sisi Oracle dihitung saat membaca baris, lalu
 *                      dibandingkan dengan hash baca-balik dari PG A.
 *   - PG A -> PG B    : hash dihitung dengan reader yang sama dari kedua sisi,
 *                      tanpa menyentuh Oracle sama sekali.
 *
 * Pembacaan dari PostgreSQL dilakukan dengan mentorjemahkan setiap kolom ke
 * teks dengan bentuk yang sudah ditentukan per tipe, supaya hasil canonical
 * identik dengan sisi sumber.
 */
class Verifier
{
    public function __construct(
        private readonly TableWriter $writer,
    ) {
    }

    /**
     * Hitung hash isi tabel di satu koneksi PostgreSQL.
     *
     * Dibaca lewat SERVER-SIDE CURSOR dengan FETCH bertahap, bukan
     * `$connection->select()` yang会把 seluruh hasil menumpuk di memori.
     * Ini memenuhi aturan "data diambil bertahap per batch".
     *
     * @param  array<string,string>  $typeMap  nama kolom => tipe PostgreSQL
     * @return array{row_count:int,hash:string}
     */
    public function hashTable(
        Connection $connection,
        string $schema,
        string $table,
        array $typeMap,
    ): array {
        $hasher = new ContentHasher;

        $select = implode(', ', array_map(
            fn (string $name) => $this->selectExpression($schema, $table, $name, $typeMap[$name]),
            array_keys($typeMap),
        ));

        $qualified = $this->writer->qualified($schema, $table);
        $types = array_values($typeMap);
        $names = array_keys($typeMap);
        $cursor = 'ods_hash_'.substr(sha1($schema.'.'.$table), 0, 16);

        $connection->transaction(function () use (
            $connection, $select, $qualified, $types, $names, $hasher, $cursor
        ): void {
            $connection->statement("DECLARE {$cursor} NO SCROLL CURSOR FOR SELECT {$select} FROM {$qualified}");

            try {
                while (true) {
                    $batch = $connection->select("FETCH {$this->fetchSize} FROM {$cursor}");

                    if ($batch === []) {
                        break;
                    }

                    foreach ($batch as $row) {
                        $values = (array) $row;
                        $canonical = [];

                        foreach ($names as $index => $name) {
                            $canonical[] = Canonicalizer::canonicalize($values[$name] ?? null, $types[$index]);
                        }

                        $hasher->addRow($canonical);
                    }
                }
            } finally {
                $connection->statement("CLOSE {$cursor}");
            }
        });

        return $hasher->toArray();
    }

    private int $fetchSize = 5000;

    /**
     * Bandingkan dua hasil hash.
     *
     * @param  array{row_count:int,hash:string}  $source
     * @param  array{row_count:int,hash:string}  $target
     * @return array<string,mixed>
     */
    public function compare(array $source, array $target): array
    {
        $rowsMatch = $source['row_count'] === $target['row_count'];
        $hashMatch = $source['hash'] === $target['hash'];

        $message = match (true) {
            $rowsMatch && $hashMatch => 'Jumlah baris dan hash isi identik.',
            $rowsMatch => 'Jumlah baris sama, tetapi hash isi BERBEDA. Ada nilai yang tidak utuh.',
            $hashMatch => 'Hash isi sama, tetapi jumlah baris berbeda.',
            default => 'Jumlah baris dan hash isi sama-sama berbeda.',
        };

        return [
            'source' => $source,
            'target' => $target,
            'rows_match' => $rowsMatch,
            'hash_match' => $hashMatch,
            'matched' => $rowsMatch && $hashMatch,
            'message' => $message,
        ];
    }

    /**
     * Hash isi tabel final HANYA untuk baris yang kunci-nya ada di tabel join
     * (staging). Dipakai verifikasi mode incremental.
     *
     * Join dilakukan di sisi PostgreSQL, jadi delta tidak pernah dimuat ke
     * memori PHP.
     *
     * @param  array<string,string>  $typeMap
     * @return array{row_count:int,hash:string}
     */
    public function hashJoinedKeys(
        Connection $connection,
        string $schema,
        string $table,
        string $joinSchema,
        string $joinTable,
        array $keyColumns,
        array $typeMap,
    ): array {
        $hasher = new ContentHasher;

        $selectParts = [];

        foreach ($typeMap as $name => $pgType) {
            $selectParts[] = $this->scalarExpression('t', $name, $pgType);
        }

        $joinCondition = implode(' AND ', array_map(
            fn (string $key) => 't.'.$this->writer->quote($key).' = s.'.$this->writer->quote($key),
            $keyColumns,
        ));

        $sql = sprintf(
            'SELECT %s FROM %s t JOIN %s s ON %s',
            implode(', ', $selectParts),
            $this->writer->qualified($schema, $table),
            $this->writer->qualified($joinSchema, $joinTable),
            $joinCondition,
        );

        $cursor = 'ods_join_'.substr(sha1($schema.'.'.$table.'.'.$joinTable), 0, 16);
        $names = array_keys($typeMap);
        $types = array_values($typeMap);

        $connection->transaction(function () use (
            $connection, $sql, $hasher, $names, $types, $cursor
        ): void {
            $connection->statement("DECLARE {$cursor} NO SCROLL CURSOR FOR {$sql}");

            try {
                while (true) {
                    $batch = $connection->select("FETCH {$this->fetchSize} FROM {$cursor}");

                    if ($batch === []) {
                        break;
                    }

                    foreach ($batch as $row) {
                        $values = (array) $row;
                        $canonical = [];

                        foreach ($names as $index => $name) {
                            $canonical[] = Canonicalizer::canonicalize($values[$name] ?? null, $types[$index]);
                        }

                        $hasher->addRow($canonical);
                    }
                }
            } finally {
                $connection->statement("CLOSE {$cursor}");
            }
        });

        return $hasher->toArray();
    }

    /**
     * Ekspresi SELECT per kolom agar bentuk teksnya deterministik.
     *
     * bytea  : base64 (dari representasi heksadesimal bawaan PDO)
     * char(n): ::varchar supaya padding blank tidak hilang (Aturan 4)
     * lain   : ::text
     */
    private function selectExpression(
        string $schema,
        string $table,
        string $name,
        string $pgType,
    ): string {
        return $this->scalarExpression($this->writer->qualified($schema, $table), $name, $pgType);
    }

    private function scalarExpression(string $relation, string $name, string $pgType): string
    {
        $quoted = $relation.'.'.$this->writer->quote($name);
        $category = PgType::categoryOf($pgType);

        return match ($category) {
            TypeCategory::Bytea => sprintf(
                "encode(%s, 'base64') AS %s",
                $quoted,
                $this->writer->quote($name),
            ),
            default => sprintf(
                '%s::%s AS %s',
                $quoted,
                // char(n) HARUS lewat ::varchar: ::text membuang blank
                // padding, sedangkan CHAR Oracle memang menyimpan padding.
                PgType::isBlankPadded($pgType) ? 'varchar' : 'text',
                $this->writer->quote($name),
            ),
        };
    }
}
