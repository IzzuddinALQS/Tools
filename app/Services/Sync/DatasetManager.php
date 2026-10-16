<?php

namespace App\Services\Sync;

use App\Enums\DatasetStatusEnum;
use App\Enums\SyncModeEnum;
use App\Models\Connection;
use App\Models\Dataset;
use App\Models\DatasetVersion;
use App\Models\Target;
use App\Services\Source\SourceReader;
use App\Services\Source\SourceReaderFactory;
use App\Services\Source\Sql\SelectGuard;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Membuat dan mengubah dataset, dengan versi tersimpan (Keamanan 3):
 * setiap perubahan query disimpan sebagai versi berisi siapa, kapan, dan isi
 * sebelum / sesudah.
 */
class DatasetManager
{
    public function __construct(
        private readonly SourceReaderFactory $readers,
        private readonly ColumnIntrospector $introspector,
        private readonly DestinationGuard $destinationGuard,
        private readonly SelectGuard $guard,
    ) {
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{dataset:Dataset,plan:SyncPlan,warnings:array<int,string>}
     */
    public function create(array $input, ?int $userId = null): array
    {
        $connection = Connection::findOrFail($input['connection_id']);
        $target = Target::findOrFail($input['target_id']);
        $sql = $this->guard->normalize((string) $input['sql']);

        $this->assertTargetIsPgA($target);

        $reader = $this->readers->for($connection);

        // Rencana ditulis lebih dulu supaya nama tujuan berasal dari metadata
        // Oracle, bukan dari ketikan user. Nama itu yang dipakai unique index.
        $plan = $this->introspector->plan(
            sql: $sql,
            reader: $reader,
            destinationSchema: $input['destination_schema'] ?? null,
            destinationTable: $input['destination_table'] ?? null,
            columnOverrides: $this->overridesFrom($input),
        );

        $this->destinationGuard->assertAvailable(
            $this->emptyDataset(),
            $target,
            $plan->schema,
            $plan->table,
        );

        $dataset = DB::transaction(function () use ($input, $connection, $target, $sql, $plan, $userId) {
            $dataset = Dataset::create([
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'connection_id' => $connection->id,
                'target_id' => $target->id,
                'sql' => $sql,
                'identity_mode' => $plan->identityMode,
                'destination_schema' => $plan->schema,
                'destination_table' => $plan->table,
                'column_overrides' => $this->overridesFrom($input),
                'sync_mode' => $input['sync_mode'] ?? SyncModeEnum::Full->value,
                'watermark_column' => $this->upperOrNull($input['watermark_column'] ?? null),
                'watermark_type' => $input['watermark_type'] ?? null,
                'key_columns' => $this->upperList($input['key_columns'] ?? []),
                'schedule_cron' => $input['schedule_cron'] ?? config('oracle-sync.defaults.schedule_cron'),
                'reconcile_cron' => $input['reconcile_cron'] ?? config('oracle-sync.defaults.reconcile_cron'),
                'timeout_seconds' => $input['timeout_seconds'] ?? config('oracle-sync.defaults.timeout_seconds'),
                'max_rows' => $input['max_rows'] ?? config('oracle-sync.defaults.max_rows'),
                'batch_size' => $input['batch_size'] ?? config('oracle-sync.defaults.batch_size'),
                'auto_copy_to' => array_map('intval', (array) ($input['auto_copy_to'] ?? [])),
                'status' => DatasetStatusEnum::Active->value,
                'current_version_no' => 1,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->snapshot($dataset, $userId, 'create', $input['note'] ?? null);

            return $dataset;
        });

        return [
            'dataset' => $dataset->refresh(),
            'plan' => $plan,
            'warnings' => $plan->allWarnings(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{dataset:Dataset,warnings:array<int,string>}
     */
    public function update(Dataset $dataset, array $input, ?int $userId = null): array
    {
        $sql = $this->guard->normalize((string) $input['sql']);

        $connection = $dataset->connection;
        $target = $dataset->target;

        $this->assertTargetIsPgA($target);

        $reader = $this->readers->for($connection);

        $plan = $this->introspector->plan(
            sql: $sql,
            reader: $reader,
            destinationSchema: $input['destination_schema'] ?? null,
            destinationTable: $input['destination_table'] ?? null,
            columnOverrides: $this->overridesFrom($input),
        );

        $this->destinationGuard->assertAvailable($dataset, $target, $plan->schema, $plan->table);

        $changed = $this->snapshotChanged($dataset, $sql, $input, $plan);

        $dataset = DB::transaction(function () use ($dataset, $input, $sql, $plan, $userId, $changed) {
            $dataset->fill([
                'name' => $input['name'] ?? $dataset->name,
                'description' => $input['description'] ?? $dataset->description,
                'sql' => $sql,
                'identity_mode' => $plan->identityMode,
                'destination_schema' => $plan->schema,
                'destination_table' => $plan->table,
                'column_overrides' => $this->overridesFrom($input),
                'sync_mode' => $input['sync_mode'] ?? $dataset->sync_mode->value,
                'watermark_column' => $this->upperOrNull($input['watermark_column'] ?? $dataset->watermark_column),
                'watermark_type' => $input['watermark_type'] ?? $dataset->watermark_type,
                'key_columns' => $this->upperList($input['key_columns'] ?? ($dataset->key_columns ?? [])),
                'schedule_cron' => $input['schedule_cron'] ?? $dataset->schedule_cron,
                'reconcile_cron' => $input['reconcile_cron'] ?? $dataset->reconcile_cron,
                'timeout_seconds' => $input['timeout_seconds'] ?? $dataset->timeout_seconds,
                'max_rows' => $input['max_rows'] ?? $dataset->max_rows,
                'batch_size' => $input['batch_size'] ?? $dataset->batch_size,
                'auto_copy_to' => array_map('intval', (array) ($input['auto_copy_to'] ?? ($dataset->auto_copy_to ?? []))),
                'updated_by' => $userId,
            ]);

            if ($changed) {
                // Versi pertama selalu 1, walau kolomnya belum pernah diisi
                // di database (nilainya null di model yang baru dibuat).
                $dataset->current_version_no = (int) ($dataset->current_version_no ?: 1) + 1;
            }

            $dataset->save();

            if ($changed) {
                $this->snapshot($dataset, $userId, 'update', $input['note'] ?? null);
            }

            return $dataset;
        });

        return [
            'dataset' => $dataset->refresh(),
            'warnings' => $plan->allWarnings(),
        ];
    }

    /**
     * Tulis versi baru berisi seluruh konfigurasi saat ini.
     */
    private function snapshot(Dataset $dataset, ?int $userId, string $changeType, ?string $note): void
    {
        DatasetVersion::create([
            'dataset_id' => $dataset->id,
            //current_version_no bisa null bila model dibuat tanpa nilai itu,
            // jadi selalu pakai default eksplisit.
            'version_no' => (int) ($dataset->current_version_no ?: 1),
            'sql' => $dataset->sql,
            'destination_schema' => $dataset->destination_schema,
            'destination_table' => $dataset->destination_table,
            'sync_mode' => $dataset->sync_mode->value,
            'watermark_column' => $dataset->watermark_column,
            'watermark_type' => $dataset->watermark_type,
            'key_columns' => $dataset->key_columns,
            'column_overrides' => $dataset->column_overrides,
            'schedule_cron' => $dataset->schedule_cron,
            'reconcile_cron' => $dataset->reconcile_cron,
            'timeout_seconds' => $dataset->timeout_seconds,
            'max_rows' => $dataset->max_rows,
            'batch_size' => $dataset->batch_size,
            'change_type' => $changeType,
            'note' => $note,
            'changed_by' => $userId,
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function snapshotChanged(Dataset $dataset, string $sql, array $input, SyncPlan $plan): bool
    {
        return $dataset->sql !== $sql
            || $dataset->destination_schema !== $plan->schema
            || $dataset->destination_table !== $plan->table
            || $dataset->sync_mode->value !== ($input['sync_mode'] ?? $dataset->sync_mode->value)
            || $dataset->watermark_column !== $this->upperOrNull($input['watermark_column'] ?? $dataset->watermark_column)
            || $dataset->key_columns !== $this->upperList($input['key_columns'] ?? ($dataset->key_columns ?? []));
    }

    private function assertTargetIsPgA(Target $target): void
    {
        if (! $target->isPgA()) {
            throw new \App\Services\Source\Exceptions\SyncException(
                'Dataset hanya boleh menulis ke PostgreSQL A. Salinan ke PG B memakai tombol Copy.',
                'target_must_be_pg_a',
            );
        }
    }

    /**
     * Placeholder tanpa id, supaya DestinationGuard bisa memeriksa ketersediaan
     * nama tabel sebelum dataset benar-benar dibuat.
     */
    private function emptyDataset(): Dataset
    {
        $dataset = new Dataset;
        $dataset->exists = false;

        return $dataset;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,string>|null
     */
    private function overridesFrom(array $input): ?array
    {
        $overrides = [];

        foreach ((array) ($input['column_overrides'] ?? []) as $column => $type) {
            $column = strtoupper(trim((string) $column));
            $type = trim((string) $type);

            if ($column === '' || $type === '') {
                continue;
            }

            $overrides[$column] = $type;
        }

        return $overrides === [] ? null : $overrides;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function upperList(array $values): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => strtoupper(trim((string) $value)),
            $values,
        )));
    }

    private function upperOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : strtoupper($value);
    }
}
