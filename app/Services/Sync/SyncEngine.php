<?php

namespace App\Services\Sync;

use App\Enums\RunKindEnum;
use App\Enums\SyncModeEnum;
use App\Models\Dataset;
use App\Models\PgIdentifier;
use App\Models\Run;
use App\Models\Target;
use App\Services\Guard\KillSwitch;
use App\Services\Pg\PgConnectionResolver;
use App\Services\Pg\SyncSchema;
use App\Services\Source\Exceptions\SyncException;
use App\Services\Source\SourceReaderFactory;
use App\Services\Verify\Verifier;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orkestrator sinkronisasi Oracle -> PostgreSQL A.
 *
 * Urutan untuk mode FULL (yang menjamin hasil identik):
 *   1. kill switch & lock
 *   2. SelectGuard
 *   3. introspeksi kolom (metadata Oracle)
 *   4. schema + tabel staging
 *   5. stream per batch ke staging, hash dihitung saat membaca
 *   6. SWAP dalam SATU transaksi
 *   7. verifikasi hash baca-balik dari PG A
 *   8. catat _sync.status
 *
 * Kalau gagal di langkah mana pun setelah 4, tabel lama tetap utuh.
 */
class SyncEngine
{
    public function __construct(
        private readonly KillSwitch $killSwitch,
        private readonly SourceReaderFactory $readers,
        private readonly ColumnIntrospector $introspector,
        private readonly PgConnectionResolver $resolver,
        private readonly SyncSchema $syncSchema,
        private readonly Verifier $verifier,
        private readonly DestinationGuard $destinationGuard,
        private readonly TableWriterFactory $writers,
    ) {
    }

    public function run(Dataset $dataset, Run $run, bool $forceFull = false): SyncResult
    {
        $this->killSwitch->assertRunning();
        $this->destinationGuard->assertDatasetIsValid($dataset);

        $target = $dataset->target;
        $connection = $this->resolver->resolve($target);
        $reader = $this->readers->for($dataset->connection);
        $writer = $this->writers->make($dataset->batch_size);

        $plan = $this->introspector->plan(
            sql: $dataset->sql,
            reader: $reader,
            destinationSchema: $dataset->identity_mode ? null : $dataset->destination_schema,
            destinationTable: $dataset->identity_mode ? null : $dataset->destination_table,
            columnOverrides: $dataset->column_overrides,
        );

        // Nama tujuan hasil resolusi, bukan sekadar yang diisi di form.
        $schema = $plan->schema;
        $table = $plan->table;

        $this->destinationGuard->assertAvailable($dataset, $target, $schema, $table);

        $stagingTable = $writer->stagingTable((string) $run->id);

        $mode = $forceFull ? SyncModeEnum::Full : $dataset->sync_mode;

        return match ($mode) {
            SyncModeEnum::Full => $this->runFull(
                $dataset, $run, $reader, $connection, $writer, $plan, $schema, $table, $stagingTable,
            ),
            SyncModeEnum::Incremental => $this->runIncremental(
                $dataset, $run, $reader, $connection, $writer, $plan, $schema, $table, $stagingTable,
            ),
        };
    }

    private function runFull(
        Dataset $dataset,
        Run $run,
        $reader,
        Connection $connection,
        TableWriter $writer,
        SyncPlan $plan,
        string $schema,
        string $table,
        string $stagingTable,
    ): SyncResult {
        $writer->createStaging($connection, $plan, $schema, $stagingTable);

        try {
            $source = $writer->streamIntoStaging(
                connection: $connection,
                plan: $plan,
                stagingSchema: $schema,
                stagingTable: $stagingTable,
                reader: $reader,
                sql: $dataset->sql,
                bindings: [],
                maxRows: (int) $dataset->max_rows,
                timeoutSeconds: (int) $dataset->timeout_seconds,
            );

            $writer->swap($connection, $schema, $table, $schema, $stagingTable);

            $targetHash = $this->verifier->hashTable($connection, $schema, $table, $plan->typeMap());
            $verification = $this->verifier->compare($source, $targetHash);

            $this->syncSchema->recordSuccess($connection, $dataset->target, $dataset->uid, [
                'dataset_name' => $dataset->name,
                'source_table' => $plan->sourceTable,
                'target_schema' => $schema,
                'target_table' => $table,
                'sync_mode' => $dataset->sync_mode->value,
                'last_run_id' => (string) $run->id,
                'row_count' => $source['row_count'],
                'content_hash' => $source['hash'],
            ]);

            return new SyncResult(
                rowCount: $source['row_count'],
                verificationMatched: $verification['matched'],
                verification: $verification,
                warnings: $plan->allWarnings(),
                executedSql: $dataset->sql,
            );
        } catch (Throwable $exception) {
            // Data lama utuh: staging dibuang, tabel target tidak disentuh.
            $writer->dropStaging($connection, $schema, $stagingTable);

            throw $exception;
        }
    }

    private function runIncremental(
        Dataset $dataset,
        Run $run,
        $reader,
        Connection $connection,
        TableWriter $writer,
        SyncPlan $plan,
        string $schema,
        string $table,
        string $stagingTable,
    ): SyncResult {
        $keyColumns = array_map('strtoupper', (array) $dataset->key_columns);
        $watermarkColumn = strtoupper((string) $dataset->watermark_column);

        // Tabel final harus ada sebelum upsert. Bila belum, buat kosong dengan
        // struktur yang sama.
        if (! $writer->tableExists($connection, $schema, $table)) {
            $writer->createStaging($connection, $plan, $schema, $table);
        }

        // ON CONFLICT butuh unique index yang sudah ada.
        $writer->ensureUniqueIndex($connection, $schema, $table, $keyColumns);

        $writer->createStaging($connection, $plan, $schema, $stagingTable);

        try {
            $previousWatermark = $this->syncSchema->watermark($connection, $dataset->uid, $watermarkColumn);

            /*
             * Run pertama (watermark masih kosong) memakai epoch yang pasti
             * lebih tua dari data mana pun, supaya SELURUH baris diambil.
             * String kosong akan menghasilkan perbandingan yang tidakvalid.
             */
            [$sql, $bindings] = $this->wrapWithWatermark(
                $dataset->sql,
                $watermarkColumn,
                $previousWatermark !== null && $previousWatermark !== '' ? $previousWatermark : '0001-01-01 00:00:00.000000',
                (int) $dataset->max_rows,
            );

            $source = $writer->streamIntoStaging(
                connection: $connection,
                plan: $plan,
                stagingSchema: $schema,
                stagingTable: $stagingTable,
                reader: $reader,
                sql: $sql,
                bindings: $bindings,
                maxRows: (int) $dataset->max_rows,
                timeoutSeconds: (int) $dataset->timeout_seconds,
            );

            // Upsert dalam satu transaksi (Keamanan 5).
            $connection->transaction(function () use ($connection, $writer, $plan, $schema, $table, $stagingTable, $keyColumns): void {
                $connection->statement(sprintf(
                    'INSERT INTO %s (%s) SELECT %s FROM %s ON CONFLICT (%s) DO UPDATE SET %s',
                    $writer->qualified($schema, $table),
                    implode(', ', array_map(fn ($c) => $writer->quote($c->name), $plan->columns)),
                    implode(', ', array_map(fn ($c) => $writer->quote($c->name), $plan->columns)),
                    $writer->qualified($schema, $stagingTable),
                    implode(', ', array_map(fn ($c) => $writer->quote($c), $keyColumns)),
                    $this->conflictAssignments($plan, $keyColumns, $writer),
                ));
            });

            // Verifikasi: bandingkan delta yang dibaca dari Oracle dengan isi
            // tabel final untuk KEY YANG SAMA (join di sisi PostgreSQL, tanpa
            // memuat delta ke memori).
            $targetHash = $this->verifier->hashJoinedKeys(
                connection: $connection,
                schema: $schema,
                table: $table,
                joinSchema: $schema,
                joinTable: $stagingTable,
                keyColumns: $keyColumns,
                typeMap: $plan->typeMap(),
            );

            $verification = $this->verifier->compare($source, $targetHash);

            $newWatermark = $this->readMaxWatermark($connection, $schema, $table, $watermarkColumn);

            if ($newWatermark !== null) {
                $this->syncSchema->setWatermark($connection, $dataset->uid, $watermarkColumn, $newWatermark);
            }

            $this->syncSchema->recordSuccess($connection, $dataset->target, $dataset->uid, [
                'dataset_name' => $dataset->name,
                'source_table' => $plan->sourceTable,
                'target_schema' => $schema,
                'target_table' => $table,
                'sync_mode' => $dataset->sync_mode->value,
                'last_run_id' => (string) $run->id,
                'row_count' => $source['row_count'],
                'content_hash' => $source['hash'],
            ]);

            $warnings = array_merge($plan->allWarnings(), [
                'Mode incremental tidak mendeteksi baris yang DIHAPUS di Oracle. '
                .'Jadwal rekonsiliasi full: '.($dataset->reconcile_cron ?: 'BELUM DISET').'.',
            ]);

            return new SyncResult(
                rowCount: $source['row_count'],
                verificationMatched: $verification['matched'],
                verification: $verification,
                warnings: $warnings,
                watermark: $newWatermark,
                executedSql: $sql,
            );
        } catch (Throwable $exception) {
            $writer->dropStaging($connection, $schema, $stagingTable);

            throw $exception;
        }
    }

    /**
     * Bungkus SQL user dengan filter watermark. Wrapper disusun TOOL, bukan
     * ditulis user.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public function wrapWithWatermark(
        string $sql,
        string $watermarkColumn,
        ?string $previousWatermark,
        int $maxRows,
    ): array {
        $quoted = PgIdentifier::quote($watermarkColumn);
        $inner = trim(rtrim(trim($sql), ';'));

        $wrapped = 'SELECT * FROM ('.$inner.') "'.$this->alias().'" '
            .'WHERE "'.$this->alias().'".'.$quoted.' > ? '
            .'ORDER BY "'.$this->alias().'".'.$quoted;

        return [$wrapped, [$previousWatermark ?? '0001-01-01 00:00:00.000000']];
    }

    private function alias(): string
    {
        return '_ods';
    }

    private function conflictAssignments(SyncPlan $plan, array $keyColumns, TableWriter $writer): string
    {
        $updates = array_values(array_filter(
            $plan->columnNames(),
            fn (string $name) => ! in_array(strtoupper($name), $keyColumns, true),
        ));

        if ($updates === []) {
            return 'NOTHING';
        }

        return implode(', ', array_map(
            fn (string $name) => $writer->quote($name).' = EXCLUDED.'.$writer->quote($name),
            $updates,
        ));
    }

    /**
     * Baca watermark tertinggi dari tabel final.
     *
     * PENTING: pakai to_char dengan pola tetap, BUKAN col::text.
     * `::text` membuang nol di akhir pecahan, sehingga
     * '2026-03-15 06:30:00.000000' menjadi '2026-03-15 06:30:00'. Aksi itu
     * membuat baris yang SUDAH diproses terbaca lagi di sync berikutnya,
     * karena perbandingan string/viewaat masih dianggap lebih besar.
     */
    private function readMaxWatermark(Connection $connection, string $schema, string $table, string $column): ?string
    {
        $quotedColumn = '"'.str_replace('"', '""', $column).'"';
        $qualified = '"'.str_replace('"', '""', $schema).'"."'.str_replace('"', '""', $table).'"';

        $row = $connection->selectOne(sprintf(
            "SELECT to_char(MAX(%s), 'YYYY-MM-DD HH24:MI:SS.US') AS value FROM %s",
            $quotedColumn,
            $qualified,
        ));

        return $row?->value;
    }
}
