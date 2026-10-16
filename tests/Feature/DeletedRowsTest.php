<?php

namespace Tests\Feature;

use App\Enums\RunStatusEnum;
use App\Enums\SyncModeEnum;
use App\Services\Sync\SyncRunner;

/**
 * Mode `full` adalah satu-satunya mode yang menjamin hasil identik, termasuk
 * baris yang DIHAPUS di Oracle.
 */
class DeletedRowsTest extends SyncTestCase
{
    public function test_baris_terhapus_hilang_setelah_sync_full(): void
    {
        $dataset = $this->dataset();

        $first = $this->runSync($dataset);
        $this->assertSame(RunStatusEnum::Success, $first->status);
        $this->assertSame(5, $first->row_count);

        $before = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');
        $this->assertCount(5, $before);

        // Di Oracle, baris PO-2026-0004 dihapus dan satu baris baru muncul.
        $set = 'after-delete';
        $this->applyFixture($set, function (array &$data): void {
            foreach ($data['tables'] as $index => $table) {
                if ($table['name'] !== 'PO_HEADERS') {
                    continue;
                }

                $data['tables'][$index]['rows'] = array_values(array_filter(
                    $table['rows'],
                    fn (array $row) => $row['PO_NUMBER'] !== 'PO-2026-0004',
                ));

                $data['tables'][$index]['rows'][] = [
                    'PO_HEADER_ID' => '5006',
                    'PO_NUMBER' => 'PO-2026-0006',
                    'VENDOR_ID' => '9003',
                    'STATUS_CODE' => 'OPEN',
                    'ORDER_DATE' => '2026-03-14 09:00:00',
                    'LAST_UPDATE_DATE' => '2026-03-15 07:45:00.000000',
                    'BUYER_NAME' => 'Rina Kusuma',
                    'REMARK' => null,
                    'APPROVED_FLAG' => 'N',
                ];
            }
        });

        $dataset->connection->update(['fixture_set' => $set]);

        $second = $this->runSync($dataset->fresh());

        $this->assertSame(RunStatusEnum::Success, $second->status);
        $this->assertTrue((bool) $second->verification['matched']);

        $after = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');

        $numbers = array_column($after, 'PO_NUMBER');
        sort($numbers);

        // Baris yang dihapus DI Oracle harus hilang dari PG A.
        $this->assertNotContains('PO-2026-0004', $numbers);
        $this->assertContains('PO-2026-0006', $numbers);
        $this->assertCount(5, $after);
    }

    public function test_baris_yang_diubah_ikut_terupdate(): void
    {
        $dataset = $this->dataset();
        $this->runSync($dataset);

        $set = 'after-update';
        $this->applyFixture($set, function (array &$data): void {
            foreach ($data['tables'] as $index => $table) {
                if ($table['name'] !== 'PO_HEADERS') {
                    continue;
                }

                foreach ($table['rows'] as $rowIndex => $row) {
                    if ($row['PO_NUMBER'] === 'PO-2026-0001') {
                        $data['tables'][$index]['rows'][$rowIndex]['STATUS_CODE'] = 'CLOSED';
                    }
                }
            }
        });

        $dataset->connection->update(['fixture_set' => $set]);

        $run = $this->runSync($dataset->fresh());

        $this->assertSame(RunStatusEnum::Success, $run->status);

        $rows = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');

        $byNumber = [];

        foreach ($rows as $row) {
            $byNumber[$row->PO_NUMBER] = $row;
        }

        $this->assertSame('CLOSED', $byNumber['PO-2026-0001']->STATUS_CODE);
    }

    public function test_sync_full_bergantian_tidak_meninggalkan_baris_kuno(): void
    {
        $dataset = $this->dataset();
        $this->runSync($dataset);

        // Tabel harus benar-benar tertukar, bukan ditumpuk.
        $this->assertSame(
            ['PO_HEADERS'],
            $this->tableNames($dataset->target, 'APPS')
        );

        $second = $this->runSync($dataset->fresh());

        $this->assertSame(5, $second->row_count);
        $this->assertSame(
            ['PO_HEADERS'],
            $this->tableNames($dataset->target, 'APPS')
        );
    }

    private function runSync(\App\Models\Dataset $dataset): \App\Models\Run
    {
        return app(SyncRunner::class)->run($dataset);
    }
}
