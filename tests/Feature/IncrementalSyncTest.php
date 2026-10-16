<?php

namespace Tests\Feature;

use App\Enums\RunKindEnum;
use App\Enums\RunStatusEnum;
use App\Enums\RunTriggerEnum;
use App\Enums\SyncModeEnum;
use App\Services\Sync\SyncRunner;

/**
 * Mode incremental: cepat untuk tabel besar, TETAPI tidak mendeteksi baris
 * yang dihapus di Oracle. Karena itu wajib disertai rekonsiliasi full.
 */
class IncrementalSyncTest extends SyncTestCase
{
    public function test_incremental_mengambil_baris_baru_lalu_memperbarui(): void
    {
        $dataset = $this->incrementalDataset();

        // Run pertama: seluruh baris (watermark masih kosong).
        $first = $this->runSync($dataset);
        $this->assertSame(RunStatusEnum::Success, $first->status);
        $this->assertSame(5, $first->row_count);

        // Di Oracle: satu baris diperbarui, satu baris baru ditambahkan.
        $set = 'incremental-2';
        $this->applyFixture($set, function (array &$data): void {
            foreach ($data['tables'] as $index => $table) {
                if ($table['name'] !== 'PO_HEADERS') {
                    continue;
                }

                foreach ($table['rows'] as $rowIndex => $row) {
                    if ($row['PO_NUMBER'] === 'PO-2026-0001') {
                        $data['tables'][$index]['rows'][$rowIndex]['STATUS_CODE'] = 'CLOSED';
                        $data['tables'][$index]['rows'][$rowIndex]['LAST_UPDATE_DATE'] = '2026-03-16 08:00:00.000000';
                    }
                }

                $data['tables'][$index]['rows'][] = [
                    'PO_HEADER_ID' => '5007',
                    'PO_NUMBER' => 'PO-2026-0007',
                    'VENDOR_ID' => '9001',
                    'STATUS_CODE' => 'OPEN',
                    'ORDER_DATE' => '2026-03-15 09:00:00',
                    'LAST_UPDATE_DATE' => '2026-03-16 08:30:00.000000',
                    'BUYER_NAME' => 'Tono',
                    'REMARK' => null,
                    'APPROVED_FLAG' => 'N',
                ];
            }
        });

        $dataset->connection->update(['fixture_set' => $set]);

        $second = $this->runSync($dataset->fresh());

        $this->assertSame(RunStatusEnum::Success, $second->status);
        $this->assertTrue((bool) $second->verification['matched']);

        // Hanya 2 baris berubah/baru yang diproses, bukan seluruh tabel.
        $this->assertSame(2, $second->row_count);
        $rows = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');
        $this->assertCount(6, $rows);

        $byNumber = [];

        foreach ($rows as $row) {
            $byNumber[$row->PO_NUMBER] = $row;
        }

        $this->assertSame('CLOSED', $byNumber['PO-2026-0001']->STATUS_CODE);
        $this->assertArrayHasKey('PO-2026-0007', $byNumber);
    }

    public function test_incremental_tidak_mendeteksi_baris_terhapus(): void
    {
        $dataset = $this->incrementalDataset();

        $this->runSync($dataset);
        $this->assertCount(5, $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS'));

        // Di Oracle, PO-2026-0004 dihapus.
        $set = 'incremental-delete';
        $this->applyFixture($set, function (array &$data): void {
            foreach ($data['tables'] as $index => $table) {
                if ($table['name'] !== 'PO_HEADERS') {
                    continue;
                }

                $data['tables'][$index]['rows'] = array_values(array_filter(
                    $table['rows'],
                    fn (array $row) => $row['PO_NUMBER'] !== 'PO-2026-0004',
                ));
            }
        });

        $dataset->connection->update(['fixture_set' => $set]);

        $run = $this->runSync($dataset->fresh());

        $this->assertSame(RunStatusEnum::Success, $run->status);

        // Keterbatasan yang DIJADIKAN DOKUMENTASI: baris lama masih ada.
        $numbers = array_column($this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS'), 'PO_NUMBER');

        $this->assertContains(
            'PO-2026-0004',
            $numbers,
            'Mode incremental memang tidak menghapus baris; inilah alasan wajibnya rekonsiliasi full.'
        );

        // Peringatan wajib muncul supaya tidak ada yang salah paham.
        $this->assertStringContainsString('DIHAPUS', implode(' ', $dataset->incrementalWarnings()));
    }

    public function test_rekonsiliasi_full_membuat_hasil_kembali_identik(): void
    {
        $dataset = $this->incrementalDataset();

        $this->runSync($dataset);

        $set = 'incremental-delete';
        $this->applyFixture($set, function (array &$data): void {
            foreach ($data['tables'] as $index => $table) {
                if ($table['name'] !== 'PO_HEADERS') {
                    continue;
                }

                $data['tables'][$index]['rows'] = array_values(array_filter(
                    $table['rows'],
                    fn (array $row) => $row['PO_NUMBER'] !== 'PO-2026-0004',
                ));
            }
        });

        $dataset->connection->update(['fixture_set' => $set]);
        $dataset->fresh();

        $this->runSync($dataset->fresh());
        $this->assertCount(5, $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS'));

        // Rekonsiliasi: jalankan FULL meski dataset-nya incremental.
        $reconcile = app(SyncRunner::class)->run(
            $dataset->fresh(),
            RunTriggerEnum::Reconcile,
            RunKindEnum::Reconcile,
            null,
            true,
        );

        $this->assertSame(RunStatusEnum::Success, $reconcile->status);
        $this->assertTrue((bool) $reconcile->verification['matched']);

        $rows = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');

        $this->assertCount(4, $rows);
        $this->assertNotContains('PO-2026-0004', array_column($rows, 'PO_NUMBER'));
    }

    public function test_watermark_tersimpan_di_schema_sync(): void
    {
        $dataset = $this->incrementalDataset();

        $this->runSync($dataset);

        $connection = $this->pgConnection($dataset->target);

        $row = $connection->selectOne(
            'SELECT column_name, value FROM "_sync"."watermarks" WHERE dataset_key = ?::uuid',
            [$dataset->uid],
        );

        $this->assertNotNull($row);
        $this->assertSame('LAST_UPDATE_DATE', $row->column_name);
        $this->assertNotEmpty($row->value);
    }

    public function test_incremental_wajib_punya_watermark_dan_kolom_kunci(): void
    {
        $dataset = $this->incrementalDataset([
            'watermark_column' => null,
        ]);

        $run = $this->runSync($dataset);

        $this->assertSame(RunStatusEnum::Failed, $run->status);
        $this->assertSame('watermark_required', $run->error_code);
    }

    private function incrementalDataset(array $attributes = []): \App\Models\Dataset
    {
        return $this->dataset(array_merge([
            'sql' => 'SELECT PO_HEADER_ID, PO_NUMBER, STATUS_CODE, ORDER_DATE, LAST_UPDATE_DATE, APPROVED_FLAG FROM APPS.PO_HEADERS',
            'sync_mode' => SyncModeEnum::Incremental,
            'watermark_column' => 'LAST_UPDATE_DATE',
            'watermark_type' => 'TIMESTAMP',
            'key_columns' => ['PO_HEADER_ID'],
            'reconcile_cron' => '0 2 * * *',
        ], $attributes));
    }

    private function runSync(\App\Models\Dataset $dataset): \App\Models\Run
    {
        return app(SyncRunner::class)->run($dataset);
    }
}
