<?php

namespace Tests\Feature;

use App\Enums\RunStatusEnum;
use App\Enums\SyncModeEnum;
use App\Services\Sync\SyncRunner;

/**
 * Aturan "sama persis": nama, tipe, isi, dan verifikasi.
 *
 * Semua test memakai driver mock, jadi tidak butuh Oracle.
 */
class FullSyncTest extends SyncTestCase
{
    public function test_identitas_nama_tabel_dan_kolom_sama_persis(): void
    {
        $dataset = $this->dataset();

        $run = $this->runSync($dataset);

        $this->assertSame(RunStatusEnum::Success, $run->status);
        $this->assertTrue((bool) $run->verification['matched']);

        // Tabel dibuat persis seperti nama Oracle: huruf besar, quoted.
        $this->assertContains('PO_HEADERS', $this->tableNames($dataset->target, 'APPS'));

        $columns = $this->columnDefinitions($dataset->target, 'APPS', 'PO_HEADERS');

        $this->assertSame([
            'PO_HEADER_ID',
            'PO_NUMBER',
            'STATUS_CODE',
            'ORDER_DATE',
            'LAST_UPDATE_DATE',
            'APPROVED_FLAG',
        ], array_column($columns, 'name'));

        // Tipe PostgreSQL hasil pemetaan dari metadata Oracle.
        $types = array_combine(
            array_column($columns, 'name'),
            array_column($columns, 'type'),
        );

        $this->assertSame('numeric(12,0)', $types['PO_HEADER_ID']);
        $this->assertSame('varchar(30)', $types['PO_NUMBER']);
        // Oracle DATE harus jadi timestamp, jam tidak boleh hilang.
        $this->assertSame('timestamp(0)', $types['ORDER_DATE']);
        $this->assertSame('timestamp(6)', $types['LAST_UPDATE_DATE']);
        $this->assertSame('char(1)', $types['APPROVED_FLAG']);
    }

    public function test_schema_bukan_nama_tetap_ikut_oracle(): void
    {
        $dataset = $this->dataset([
            'sql' => 'SELECT RECEIPT_HEADER_ID, RECEIPT_NUMBER, RECEIVED_DATE FROM APPS.RECEIPT_HEADERS',
            'destination_table' => 'RECEIPT_HEADERS',
        ]);

        $run = $this->runSync($dataset);

        $this->assertSame(RunStatusEnum::Success, $run->status);
        $this->assertContains('RECEIPT_HEADERS', $this->tableNames($dataset->target, 'APPS'));
    }

    public function test_verifikasi_hash_berhasil(): void
    {
        $dataset = $this->dataset();

        $run = $this->runSync($dataset);

        $this->assertSame('ok', $run->verificationBadge());
        $this->assertTrue($run->verification['rows_match']);
        $this->assertTrue($run->verification['hash_match']);
        $this->assertSame(5, $run->row_count);
        $this->assertNotEmpty($run->verification['source']['hash']);
    }

    public function test_nilai_disalin_apa_adanya_tanpa_trim(): void
    {
        $set = 'trim-check';

        $this->applyFixture($set, function (array &$data): void {
            foreach ($data['tables'] as $index => $table) {
                if ($table['name'] === 'PO_HEADERS') {
                    $data['tables'][$index]['rows'][0]['STATUS_CODE'] = '  APPROVED  ';
                    $data['tables'][$index]['rows'][0]['REMARK'] = null;
                }
            }
        });

        $dataset = $this->dataset([
            'sql' => 'SELECT PO_HEADER_ID, STATUS_CODE, REMARK FROM APPS.PO_HEADERS WHERE PO_HEADER_ID = 5001',
            'destination_table' => 'PO_HEADERS',
        ], $this->mockConnection($set));

        $run = $this->runSync($dataset);

        $this->assertSame(RunStatusEnum::Success, $run->status);

        $rows = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');

        $byStatus = [];

        foreach ($rows as $row) {
            $byStatus[$row->PO_HEADER_ID] = $row;
        }

        $this->assertSame('  APPROVED  ', $byStatus[5001]->STATUS_CODE);

        // NULL tetap NULL.
        $this->assertNull($byStatus[5001]->REMARK);
    }

    public function test_char_penyimpan_blank_padding(): void
    {
        $dataset = $this->dataset();

        $run = $this->runSync($dataset);

        $this->assertSame(RunStatusEnum::Success, $run->status);

        $rows = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');

        // APPROVED_FLAG adalah CHAR(1); PO_HEADERS fixture memakai 'Y'/'N'.
        $this->assertContains($rows[0]->APPROVED_FLAG, ['Y', 'N']);
    }

    public function test_sync_berjalan_ulang_memberikan_hash_yang_sama(): void
    {
        $dataset = $this->dataset();

        $first = $this->runSync($dataset);
        $second = $this->runSync($dataset);

        $this->assertSame(
            $first->verification['source']['hash'],
            $second->verification['source']['hash']
        );
    }

    public function test_status_sync_tersimpan_di_schema_sync(): void
    {
        $dataset = $this->dataset();

        $run = $this->runSync($dataset);

        $connection = $this->pgConnection($dataset->target);

        $row = $connection->selectOne(
            'SELECT dataset_name, target_schema, target_table, last_success_at, row_count, content_hash
               FROM "_sync"."status" WHERE dataset_key = ?::uuid AND target_kind = ?',
            [$dataset->uid, 'pg_a'],
        );

        $this->assertNotNull($row);
        $this->assertSame($dataset->name, $row->dataset_name);
        $this->assertSame('APPS', $row->target_schema);
        $this->assertSame('PO_HEADERS', $row->target_table);
        $this->assertSame(5, (int) $row->row_count);
        $this->assertSame($run->verification['source']['hash'], $row->content_hash);
        $this->assertNotNull($row->last_success_at);
    }

    public function test_tabel_data_tidak_dapat_kolom_teknis(): void
    {
        $dataset = $this->dataset();

        $this->runSync($dataset);

        $names = array_column(
            $this->columnDefinitions($dataset->target, 'APPS', 'PO_HEADERS'),
            'name',
        );

        foreach (['_sync_status', 'last_updated_at', 'row_id', 'hash', 'sync_hash'] as $forbidden) {
            $this->assertNotContains($forbidden, $names);
        }
    }

    /**
     * @return array<int,array{name:string,type:string}>
     */
    protected function columnDefinitions(\App\Models\Target $target, string $schema, string $table): array
    {
        $rows = $this->pgConnection($target)->select(
            'SELECT column_name AS name,
                    data_type AS type,
                    character_maximum_length AS len,
                    numeric_precision AS num_precision,
                    numeric_scale AS num_scale,
                    datetime_precision AS dt_precision
               FROM information_schema.columns
              WHERE table_schema = ? AND table_name = ?
              ORDER BY ordinal_position',
            [$schema, $table],
        );

        return array_map(function ($row): array {
            $type = (string) $row->type;

            if ($row->len !== null && in_array($type, ['character varying', 'character'], true)) {
                $base = $type === 'character' ? 'char' : 'varchar';
                $type = $base.'('.$row->len.')';
            }

            if ($type === 'timestamp without time zone') {
                $type = 'timestamp('.($row->dt_precision ?? 0).')';
            }

            if ($type === 'numeric' && $row->num_precision !== null) {
                $type = 'numeric('.$row->num_precision.','.($row->num_scale ?? 0).')';
            }

            return ['name' => (string) $row->name, 'type' => $type];
        }, $rows);
    }

    private function runSync(\App\Models\Dataset $dataset): \App\Models\Run
    {
        return app(SyncRunner::class)->run($dataset);
    }
}

