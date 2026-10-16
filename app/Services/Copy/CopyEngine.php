<?php

namespace App\Services\Copy;

use App\Enums\RunKindEnum;
use App\Models\Dataset;
use App\Models\PgIdentifier;
use App\Models\Run;
use App\Models\Target;
use App\Services\Guard\KillSwitch;
use App\Services\Pg\PgConnectionResolver;
use App\Services\Pg\SyncSchema;
use App\Services\ReadOnly\ReadOnlyProvisioner;
use App\Services\Source\Exceptions\SyncException;
use App\Services\Sync\PlannedColumn;
use App\Services\Sync\TableWriterFactory;
use App\Services\Types\PgType;
use App\Services\Verify\Verifier;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Salin tabel dari PostgreSQL A ke PostgreSQL B.
 *
 * TIDAK menyentuh Oracle sama sekali. Kalau B salah atau rusak, B tinggal
 * di-copy ulang dari A.
 *
 * Verifikasi dilakukan dengan membandingkan hash isi A dengan hash isi B,
 * keduanya dihitung oleh tool.
 */
class CopyEngine
{
    public function __construct(
        private readonly KillSwitch $killSwitch,
        private readonly PgConnectionResolver $resolver,
        private readonly SyncSchema $syncSchema,
        private readonly Verifier $verifier,
        private readonly TableWriterFactory $writers,
        private readonly ReadOnlyProvisioner $readOnly,
    ) {
    }

    /**
     * @return array{row_count:int,hash:string,verification:array<string,mixed>,warnings:array<int,string>}
     */
    public function run(Dataset $dataset, Target $destination, Run $run): array
    {
        $this->killSwitch->assertRunning();

        $source = $dataset->target;

        if (! $source->isPgA()) {
            throw new SyncException('Sumber copy harus PostgreSQL A.', 'invalid_copy_source');
        }

        if ($destination->id === $source->id) {
            throw new SyncException('Target copy harus berbeda dari PostgreSQL A.', 'invalid_copy_target');
        }

        $sourceConnection = $this->resolver->resolve($source);
        $destinationConnection = $this->resolver->resolve($destination);

        $schema = $dataset->destination_schema;
        $table = $dataset->destination_table;

        $columns = $this->introspectTable($sourceConnection, $schema, $table);
        $writer = $this->writers->make((int) $dataset->batch_size);
        $typeMap = [];

        foreach ($columns as $column) {
            $typeMap[$column->name] = $column->pgType;
        }

        // Hash sisi A dihitung dengan reader yang sama seperti sisi B, supaya
        // perbandingan benar-benar apples-to-apples.
        $sourceHash = $this->verifier->hashTable($sourceConnection, $schema, $table, $typeMap);

        $stagingTable = $writer->stagingTable((string) $run->id);
        $writer->createSchema($destinationConnection, $schema);
        $writer->createStaging($destinationConnection, $this->plan($columns, $schema, $table), $schema, $stagingTable);

        try {
            $this->copyRows(
                $sourceConnection, $destinationConnection,
                $schema, $table, $stagingTable,
                $columns, $typeMap, $writer,
            );

            $grants = $this->grantsFor($destination, $schema, $table);

            $writer->swap(
                $destinationConnection,
                $schema, $table,
                $schema, $stagingTable,
                [],
                $grants,
            );

            $destinationHash = $this->verifier->hashTable($destinationConnection, $schema, $table, $typeMap);
            $verification = $this->verifier->compare($sourceHash, $destinationHash);

            $this->syncSchema->recordSuccess($destinationConnection, $destination, $dataset->uid, [
                'dataset_name' => $dataset->name,
                'source_table' => $schema.'.'.$table,
                'target_schema' => $schema,
                'target_table' => $table,
                'sync_mode' => 'copy',
                'last_run_id' => (string) $run->id,
                'row_count' => $destinationHash['row_count'],
                'content_hash' => $destinationHash['hash'],
            ]);

            $this->syncSchema->recordRun($destinationConnection, [
                'run_id' => (string) $run->id,
                'dataset_key' => $dataset->uid,
                'status' => $run->status->value,
                'row_count' => $destinationHash['row_count'],
                'started_at' => $run->started_at,
                'finished_at' => now(),
                'error_message' => null,
            ]);

            return [
                'row_count' => $destinationHash['row_count'],
                'hash' => $destinationHash['hash'],
                'verification' => $verification,
                'warnings' => $this->readOnly->warningsFor($destination),
            ];
        } catch (Throwable $exception) {
            $writer->dropStaging($destinationConnection, $schema, $stagingTable);

            throw $exception;
        }
    }

    /**
     * Struktur tabel dibaca langsung dari PostgreSQL A lewat katalog, bukan
     * dari Oracle.
     *
     * @return array<int,PlannedColumn>
     */
    public function introspectTable(Connection $connection, string $schema, string $table): array
    {
        $rows = $connection->select(
            'SELECT a.attname AS name,
                    format_type(a.atttypid, a.atttypmod) AS pg_type
               FROM pg_attribute a
               JOIN pg_class c ON c.oid = a.attrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = ?
                AND c.relname = ?
                AND a.attnum > 0
                AND NOT a.attisdropped
              ORDER BY a.attnum',
            [$schema, $table],
        );

        if ($rows === []) {
            throw new SyncException(
                'Tabel "'.$schema.'"."'.$table.'" tidak ada di PostgreSQL A. Jalankan sync dataset ini dulu.',
                'source_table_missing',
            );
        }

        $columns = [];

        foreach ($rows as $row) {
            $columns[] = new PlannedColumn(
                name: (string) $row->name,
                pgType: (string) $row->pg_type,
                category: PgType::categoryOf((string) $row->pg_type),
            );
        }

        return $columns;
    }

    /**
     * @param  array<int,PlannedColumn>  $columns
     */
    private function plan(array $columns, string $schema, string $table): \App\Services\Sync\SyncPlan
    {
        return new \App\Services\Sync\SyncPlan(
            schema: $schema,
            table: $table,
            identityMode: true,
            columns: $columns,
        );
    }

    /**
     * @param  array<int,PlannedColumn>  $columns
     * @param  array<string,string>  $typeMap
     */
    private function copyRows(
        Connection $source,
        Connection $destination,
        string $schema,
        string $table,
        string $stagingTable,
        array $columns,
        array $typeMap,
        $writer,
    ): void {
        $names = array_map(fn (PlannedColumn $c) => $c->name, $columns);

        $select = implode(', ', array_map(
            fn (PlannedColumn $c) => $c->quoted(),
            $columns,
        ));

        $stagingQualified = $writer->qualified($schema, $stagingTable);

        $cursor = 'ods_copy_'.substr(sha1($schema.$table.$stagingTable), 0, 16);
        $batchSize = (int) config('oracle-sync.defaults.batch_size', 2000);

        $source->transaction(function () use (
            $source, $destination, $schema, $table, $select, $stagingQualified,
            $cursor, $batchSize, $names
        ): void {
            $qualified = '"'.str_replace('"', '""', $schema).'"."'.str_replace('"', '""', $table).'"';
            $source->statement("DECLARE {$cursor} NO SCROLL CURSOR FOR SELECT {$select} FROM {$qualified}");

            try {
                while (true) {
                    $batch = [];

                    foreach ($source->select("FETCH {$batchSize} FROM {$cursor}") as $row) {
                        $data = (array) $row;
                        $mapped = [];

                        foreach ($names as $name) {
                            $mapped[$name] = $data[$name] ?? null;
                        }

                        $batch[] = $mapped;
                    }

                    if ($batch === []) {
                        break;
                    }

                    $destination->table(DB::raw($stagingQualified))->insert($batch);
                }
            } finally {
                $source->statement("CLOSE {$cursor}");
            }
        });
    }

    /**
     * @return array<int,string>
     */
    private function grantsFor(Target $destination, string $schema, string $table): array
    {
        if (empty($destination->readonly_username)) {
            return [];
        }

        $user = PgIdentifier::quote($destination->readonly_username);

        return [
            sprintf('GRANT USAGE ON SCHEMA %s TO %s', PgIdentifier::quote($schema), $user),
            sprintf('GRANT SELECT ON TABLE %s TO %s', PgIdentifier::qualify($schema, $table), $user),
        ];
    }
}
