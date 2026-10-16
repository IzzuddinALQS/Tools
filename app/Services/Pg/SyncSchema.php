<?php

namespace App\Services\Pg;

use App\Models\Target;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Schema `_sync` di PG A dan PG B (Aturan 6).
 *
 * TABEL DATA TIDAK PERNAH dapat kolom teknis. Semua status sync disimpan di
 * sini, terpisah, supaya aplikasi bisa menampilkan
 * "data terakhir diperbarui pukul ..." tanpa menyentuh tabel data.
 *
 * `target_kind` + `app_name` + `dataset_key` menjadi kunci utama supaya PG A
 * dan beberapa PG B bisa memakai nama tabel yang sama tanpa saling menimpa.
 */
class SyncSchema
{
    public function __construct(
        private readonly PgConnectionResolver $resolver,
    ) {
    }

    public function schemaName(): string
    {
        return (string) config('oracle-sync.meta_schema', '_sync');
    }

    public function ensure(Connection $connection): void
    {
        $schema = $this->schemaName();

        $connection->statement('CREATE SCHEMA IF NOT EXISTS '.$this->quote($schema));

        $connection->statement(<<<SQL
            CREATE TABLE IF NOT EXISTS {$schema}.status (
                target_kind     text        NOT NULL,
                app_name        text        NOT NULL DEFAULT '',
                dataset_key     uuid        NOT NULL,
                dataset_name    text        NOT NULL,
                source_table    text,
                target_schema   text        NOT NULL,
                target_table    text        NOT NULL,
                sync_mode       text        NOT NULL,
                last_success_at timestamptz,
                last_run_id     text,
                row_count       bigint,
                content_hash    text,
                updated_at      timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (target_kind, app_name, dataset_key)
            )
            SQL);

        $connection->statement(<<<SQL
            CREATE TABLE IF NOT EXISTS {$schema}.watermarks (
                dataset_key uuid   NOT NULL,
                column_name text   NOT NULL,
                value       text,
                updated_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (dataset_key, column_name)
            )
            SQL);

        $connection->statement(<<<SQL
            CREATE TABLE IF NOT EXISTS {$schema}.runs (
                run_id        text PRIMARY KEY,
                dataset_key   uuid        NOT NULL,
                status        text        NOT NULL,
                row_count     bigint,
                started_at    timestamptz,
                finished_at   timestamptz,
                error_message text,
                updated_at    timestamptz NOT NULL DEFAULT now()
            )
            SQL);
    }

    public function ensureFor(Target $target): Connection
    {
        $connection = $this->resolver->resolve($target);

        $this->ensure($connection);

        return $connection;
    }

    /**
     * Catat hasil sync yang sukses supaya aplikasi bisa membacanya.
     */
    public function recordSuccess(
        Connection $connection,
        Target $target,
        string $datasetKey,
        array $attributes,
    ): void {
        $this->ensure($connection);

        $schema = $this->schemaName();

        $sql = <<<SQL
            INSERT INTO {$schema}.status
                (target_kind, app_name, dataset_key, dataset_name, source_table,
                 target_schema, target_table, sync_mode, last_success_at,
                 last_run_id, row_count, content_hash, updated_at)
            VALUES (?, ?, ?::uuid, ?, ?, ?, ?, ?, now(), ?, ?, ?, now())
            ON CONFLICT (target_kind, app_name, dataset_key) DO UPDATE SET
                dataset_name    = EXCLUDED.dataset_name,
                source_table    = EXCLUDED.source_table,
                target_schema   = EXCLUDED.target_schema,
                target_table    = EXCLUDED.target_table,
                sync_mode       = EXCLUDED.sync_mode,
                last_success_at = EXCLUDED.last_success_at,
                last_run_id     = EXCLUDED.last_run_id,
                row_count       = EXCLUDED.row_count,
                content_hash    = EXCLUDED.content_hash,
                updated_at      = EXCLUDED.updated_at
            SQL;

        $connection->statement($sql, [
            $target->kind->value,
            $target->app_name ?? '',
            $datasetKey,
            $attributes['dataset_name'],
            $attributes['source_table'] ?? null,
            $attributes['target_schema'],
            $attributes['target_table'],
            $attributes['sync_mode'],
            $attributes['last_run_id'] ?? null,
            $attributes['row_count'] ?? null,
            $attributes['content_hash'] ?? null,
        ]);
    }

    public function recordRun(Connection $connection, array $run): void
    {
        $this->ensure($connection);

        $schema = $this->schemaName();

        $sql = <<<SQL
            INSERT INTO {$schema}.runs
                (run_id, dataset_key, status, row_count, started_at, finished_at, error_message, updated_at)
            VALUES (?, ?::uuid, ?, ?, ?, ?, ?, now())
            ON CONFLICT (run_id) DO UPDATE SET
                status        = EXCLUDED.status,
                row_count     = EXCLUDED.row_count,
                finished_at   = EXCLUDED.finished_at,
                error_message = EXCLUDED.error_message,
                updated_at    = EXCLUDED.updated_at
            SQL;

        $connection->statement($sql, [
            $run['run_id'],
            $run['dataset_key'],
            $run['status'],
            $run['row_count'] ?? null,
            $run['started_at'] ?? null,
            $run['finished_at'] ?? null,
            $run['error_message'] ?? null,
        ]);
    }

    public function watermark(Connection $connection, string $datasetKey, string $column): ?string
    {
        $this->ensure($connection);

        $value = $connection->selectOne(
            sprintf(
                'SELECT value FROM %s.watermarks WHERE dataset_key = ?::uuid AND column_name = ?',
                $this->schemaName()
            ),
            [$datasetKey, $column],
        );

        return $value?->value;
    }

    public function setWatermark(Connection $connection, string $datasetKey, string $column, ?string $value): void
    {
        $this->ensure($connection);

        $connection->statement(
            sprintf(
                <<<SQL
                INSERT INTO %s.watermarks (dataset_key, column_name, value, updated_at)
                VALUES (?::uuid, ?, ?, now())
                ON CONFLICT (dataset_key, column_name) DO UPDATE SET
                    value = EXCLUDED.value,
                    updated_at = now()
                SQL,
                $this->schemaName()
            ),
            [$datasetKey, $column, $value],
        );
    }

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
