<?php

namespace App\Services\Sync;

use App\Models\PgIdentifier;
use App\Services\Source\Exceptions\SyncException;
use App\Services\Source\SourceReader;
use App\Services\Verify\Canonicalizer;
use App\Services\Verify\ContentHasher;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menulis hasil query ke PostgreSQL: staging per batch, lalu SWAP dalam satu
 * transaksi.
 *
 * Keamanan 5: DDL PostgreSQL bersifat transaksional, jadi DROP + ALTER ...
 * RENAME di dalam BEGIN/COMMIT bersifat atomik. Kalau gagal di tengah, tabel
 * lama tidak pernah tersentuh.
 */
class TableWriter
{
    public function __construct(
        private readonly int $batchSize = 2000,
    ) {
    }

    public function qualified(string $schema, string $table): string
    {
        return PgIdentifier::qualify($schema, $table);
    }

    public function quote(string $identifier): string
    {
        return PgIdentifier::quote($identifier);
    }

    public function stagingTable(string $runId): string
    {
        return (string) config('oracle-sync.staging.suffix_prefix', '__stg_')
            .preg_replace('/[^A-Za-z0-9]/', '', $runId);
    }

    public function createSchema(Connection $connection, string $schema): void
    {
        $connection->statement('CREATE SCHEMA IF NOT EXISTS '.$this->quote($schema));
    }

    public function createStaging(Connection $connection, SyncPlan $plan, string $schema, string $table): void
    {
        $this->createSchema($connection, $schema);

        $qualified = $this->qualified($schema, $table);
        $connection->statement('DROP TABLE IF EXISTS '.$qualified);
        $connection->statement($plan->createTableSql($qualified));
    }

    /**
     * Tulis seluruh baris ke tabel staging per batch.
     *
     * Hash sisi Oracle dihitung DI SINI, dari baris yang sedang dibaca,
     * tanpa query tambahan ke Oracle (Aturan 5).
     *
     * @return array{row_count:int,hash:string}
     */
    public function streamIntoStaging(
        Connection $connection,
        SyncPlan $plan,
        string $stagingSchema,
        string $stagingTable,
        SourceReader $reader,
        string $sql,
        array $bindings,
        int $maxRows,
        int $timeoutSeconds,
        ?callable $onProgress = null,
    ): array {
        $qualified = $this->qualified($stagingSchema, $stagingTable);
        $names = $plan->columnNames();
        $types = $plan->pgTypes();

        $hasher = new ContentHasher;
        $written = 0;
        $batch = [];

        $flush = function () use (&$batch, $connection, $qualified): void {
            if ($batch === []) {
                return;
            }

            // table(DB::raw(...)) dipakai supaya nama tabel yang sudah
            // ter-quote ("APPS"."T") tidak di-quote dua kali menjadi
            // "APPS"."T". Builder juga menyusun multi-row VALUES sendiri.
            $connection->table(DB::raw($qualified))->insert($batch);
            $batch = [];
        };

        /*
         * Ambil satu baristambahan: kalau memang ada baris ke-(maxRows+1),
         * berarti hasil query melebihi batas. Memotong diam-diam akan
         * menghasilkan tabel yang isinya tidak lengkap tapi verifikasi tetap
         * hijau, karena hash dihitung dari data yang sudah terpotong.
         * Lebih aman gagal dengan jelas.
         */
        $cursor = $reader->stream($sql, $bindings, $maxRows + 1, $timeoutSeconds);

        foreach ($cursor->rows() as $row) {
            if ($written >= $maxRows) {
                $this->dropStaging($connection, $stagingSchema, $stagingTable);

                throw new SyncException(
                    "Query menghasilkan lebih dari {$maxRows} baris, melebihi batas baris dataset. "
                    .'Batas ada supaya satu dataset tidak pernah membanjiri PostgreSQL A. '
                    .'Tambah filter WHERE, naikkan batas baris, atau pakai mode incremental.',
                    'max_rows_exceeded',
                    ['max_rows' => $maxRows],
                );
            }

            $mapped = [];

            foreach ($names as $index => $name) {
                $value = $row[$name] ?? null;
                $mapped[$name] = $value;
            }

            $batch[] = $mapped;
            $hasher->addRow($this->canonicalizeRow($row, $names, $types));
            $written++;

            if (count($batch) >= $this->batchSize) {
                $flush();
            }

            if ($onProgress !== null) {
                $onProgress($written);
            }
        }

        $flush();

        return $hasher->toArray();
    }

    /**
     * Swap atomik: ganti tabel lama dengan staging, dalam SATU transaksi.
     *
     * @param  array<int,string>  $keyColumns  kolom kunci untuk unique index
     * @param  array<int,string>  $grants     [])
     */
    public function swap(
        Connection $connection,
        string $schema,
        string $table,
        string $stagingSchema,
        string $stagingTable,
        array $keyColumns = [],
        array $grants = [],
    ): void {
        $target = $this->qualified($schema, $table);
        $staging = $this->qualified($stagingSchema, $stagingTable);

        $connection->transaction(function () use (
            $connection, $target, $staging, $schema, $table, $keyColumns, $grants
        ): void {
            $connection->statement('DROP TABLE IF EXISTS '.$target);
            $connection->statement('ALTER TABLE '.$staging.' RENAME TO '.$this->quote($table));

            if ($keyColumns !== []) {
                $indexName = 'ux_'.substr(sha1($schema.'.'.$table), 0, 24);

                $connection->statement(sprintf(
                    'CREATE UNIQUE INDEX %s ON %s (%s)',
                    $this->quote($indexName),
                    $target,
                    implode(', ', array_map(fn (string $c) => $this->quote($c), $keyColumns)),
                ));
            }

            foreach ($grants as $grant) {
                $connection->statement($grant);
            }
        });
    }

    /**
     * Pastikan unique index pada kolom kunci ada.
     *
     * `ON CONFLICT (cols)` PostgreSQL MEMBUTUHKAN unique index yang sudah
     * ada. Index itu baru ada setelah swap pertama, jadi harus dipastikan
     * eksplisit sebelum upsert.
     *
     * @param  array<int,string>  $keyColumns
     */
    public function ensureUniqueIndex(Connection $connection, string $schema, string $table, array $keyColumns): void
    {
        if ($keyColumns === []) {
            return;
        }

        $indexName = 'ux_'.substr(sha1($schema.'.'.$table), 0, 24);

        $exists = $connection->selectOne(
            'SELECT 1 FROM pg_indexes WHERE schemaname = ? AND indexname = ?',
            [$schema, $indexName],
        );

        if ($exists !== null) {
            return;
        }

        $connection->statement(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (%s)',
            $this->quote($indexName),
            $this->qualified($schema, $table),
            implode(', ', array_map(fn (string $c) => $this->quote($c), $keyColumns)),
        ));
    }

    /**
     * Bersihkan tabel staging bila ada (saat gagal di tengah).
     */
    public function dropStaging(Connection $connection, string $schema, string $table): void
    {
        try {
            $connection->statement('DROP TABLE IF EXISTS '.$this->qualified($schema, $table));
        } catch (Throwable $exception) {
            Log::warning('Gagal membersihkan tabel staging.', [
                'schema' => $schema,
                'table' => $table,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Upsert satu batch ke tabel final (mode incremental).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $keyColumns
     */
    public function upsertBatch(
        Connection $connection,
        SyncPlan $plan,
        string $schema,
        string $table,
        array $rows,
        array $keyColumns,
    ): void {
        if ($rows === []) {
            return;
        }

        $names = $plan->columnNames();

        $sql = $this->upsertSql($plan, $schema, $table, $keyColumns);

        $values = [];

        foreach ($rows as $row) {
            $tuple = [];

            foreach ($names as $name) {
                $tuple[] = $row[$name] ?? null;
            }

            $values[] = $tuple;
        }

        $connection->insert($sql, $values);
    }

    public function insertSql(SyncPlan $plan, string $qualifiedTable): string
    {
        $columns = array_map(fn (PlannedColumn $c) => $c->quoted(), $plan->columns);

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $qualifiedTable,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
    }

    public function upsertSql(SyncPlan $plan, string $schema, string $table, array $keyColumns): string
    {
        $names = $plan->columnNames();
        $upperKeys = array_map('strtoupper', $keyColumns);

        $updates = array_values(array_filter(
            $names,
            fn (string $name) => ! in_array(strtoupper($name), $upperKeys, true),
        ));

        $conflict = $updates === []
            ? 'DO NOTHING'
            : 'DO UPDATE SET '.implode(', ', array_map(
                fn (string $name) => $this->quote($name).' = EXCLUDED.'.$this->quote($name),
                $updates,
            ));

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) %s',
            $this->qualified($schema, $table),
            implode(', ', array_map(fn (string $n) => $this->quote($n), $names)),
            implode(', ', array_fill(0, count($names), '?')),
            implode(', ', array_map(fn (string $c) => $this->quote($c), $keyColumns)),
            $conflict,
        );
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<int,string>  $names
     * @param  array<int,string>  $types
     * @return array<int,?string>
     */
    public function canonicalizeRow(array $row, array $names, array $types): array
    {
        $canonical = [];

        foreach ($names as $index => $name) {
            $canonical[] = Canonicalizer::canonicalize($row[$name] ?? null, $types[$index]);
        }

        return $canonical;
    }

    public function tableExists(Connection $connection, string $schema, string $table): bool
    {
        $row = $connection->selectOne(
            'SELECT EXISTS (SELECT 1 FROM information_schema.tables
                              WHERE table_schema = ? AND table_name = ?)',
            [$schema, $table],
        );

        return (bool) ($row->exists ?? false);
    }
}
