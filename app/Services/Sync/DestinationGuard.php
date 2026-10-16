<?php

namespace App\Services\Sync;

use App\Enums\SyncModeEnum;
use App\Models\Dataset;
use App\Models\Target;
use App\Services\Source\Exceptions\SyncException;

/**
 * Menjaga aturan: satu tabel tujuan hanya boleh dimiliki SATU dataset
 * (Aturan 2).
 *
 * Ini dicek dua kali:
 *  1. unique index di database untukdestination_schema/destination_table
 *  2. validasi lebih eksplisit di sini, supaya pesannya ramah untuk user
 */
class DestinationGuard
{
    /**
     * @throws SyncException
     */
    public function assertAvailable(
        Dataset $dataset,
        Target $target,
        string $schema,
        string $table,
        bool $ignoreCurrent = true,
    ): void {
        $query = Dataset::query()
            ->where('target_id', $target->id)
            ->where('destination_schema', $schema)
            ->where('destination_table', $table);

        if ($ignoreCurrent && $dataset->exists) {
            $query->whereKeyNot($dataset->id);
        }

        /** @var Dataset|null $conflict */
        $conflict = $query->first();

        if ($conflict === null) {
            return;
        }

        throw new SyncException(
            'Tabel tujuan "'.$schema.'"."'.$table.'" di PostgreSQL A sudah dipakai dataset "'
            .$conflict->name.'". Satu tabel tujuan hanya boleh dimiliki satu dataset.',
            'destination_taken',
            ['dataset' => $conflict->name],
        );
    }

    /**
     * Validasi konfigurasi dataset sebelum disimpan.
     *
     * @throws SyncException
     */
    public function assertDatasetIsValid(Dataset $dataset): void
    {
        if ($dataset->sync_mode === SyncModeEnum::Incremental) {
            if (empty($dataset->watermark_column)) {
                throw new SyncException(
                    'Mode incremental wajib mengisi kolom watermark.',
                    'watermark_required',
                );
            }

            if (empty($dataset->key_columns)) {
                throw new SyncException(
                    'Mode incremental wajib mengisi kolom kunci (key columns) untuk upsert.',
                    'key_columns_required',
                );
            }
        }

        if ($dataset->batch_size < 1) {
            throw new SyncException('Ukuran batch minimal 1 baris.', 'invalid_batch_size');
        }

        if ($dataset->max_rows < 1) {
            throw new SyncException('Batas baris maksimal minimal 1.', 'invalid_max_rows');
        }

        if ($dataset->timeout_seconds < 1) {
            throw new SyncException('Timeout minimal 1 detik.', 'invalid_timeout');
        }
    }
}
