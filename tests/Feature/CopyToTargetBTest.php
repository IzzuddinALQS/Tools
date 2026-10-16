<?php

namespace Tests\Feature;

use App\Enums\RunKindEnum;
use App\Enums\RunStatusEnum;
use App\Models\Run;
use App\Models\Target;
use App\Services\Copy\CopyEngine;
use App\Services\Sync\SyncRunner;

/**
 * Copy PostgreSQL A -> PostgreSQL B.
 *
 * Tidak menyentuh Oracle sama sekali. Kalau B salah atau rusak, B tinggal
 * di-copy ulang dari A.
 */
class CopyToTargetBTest extends SyncTestCase
{
    public function test_struktur_dan_isi_b_identik_dengan_a(): void
    {
        $dataset = $this->dataset();
        $targetB = $this->pgB('armors');

        $sync = app(SyncRunner::class)->run($dataset);
        $this->assertSame(RunStatusEnum::Success, $sync->status);

        $result = app(CopyEngine::class)->run($dataset, $targetB, $this->startCopyRun($dataset, $targetB));

        $this->assertTrue((bool) $result['verification']['matched']);

        // Nama tabel sama persis di kedua sisi.
        $this->assertContains('PO_HEADERS', $this->tableNames($dataset->target, 'APPS'));
        $this->assertContains('PO_HEADERS', $this->tableNames($targetB, 'APPS'));

        // Isi sama persis.
        $fromA = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');
        $fromB = $this->fetchTable($targetB, 'APPS', 'PO_HEADERS');

        $this->assertCount(5, $fromA);
        $this->assertCount(5, $fromB);
        $this->assertEquals(
            array_map(fn ($row) => (array) $row, $fromA),
            array_map(fn ($row) => (array) $row, $fromB),
        );
    }

    public function test_struktur_kolom_b_sama_dengan_a(): void
    {
        $dataset = $this->dataset();
        $targetB = $this->pgB('armors');

        app(SyncRunner::class)->run($dataset);
        app(CopyEngine::class)->run($dataset, $targetB, $this->startCopyRun($dataset, $targetB));

        $connectionB = $this->pgConnection($targetB);

        $columns = $connectionB->select(
            'SELECT column_name AS name, data_type AS type, character_maximum_length AS len,
                    numeric_precision AS num_precision, numeric_scale AS num_scale
               FROM information_schema.columns
              WHERE table_schema = ? AND table_name = ?
              ORDER BY ordinal_position',
            ['APPS', 'PO_HEADERS'],
        );

        $names = array_map(fn ($row) => $row->name, $columns);

        $this->assertSame(
            ['PO_HEADER_ID', 'PO_NUMBER', 'STATUS_CODE', 'ORDER_DATE', 'LAST_UPDATE_DATE', 'APPROVED_FLAG'],
            $names,
        );

        $types = [];

        foreach ($columns as $column) {
            $type = (string) $column->type;

            if ($column->len !== null && in_array($type, ['character varying', 'character'], true)) {
                $type = ($type === 'character' ? 'char' : 'varchar').'('.$column->len.')';
            }

            if ($type === 'timestamp without time zone') {
                $type = 'timestamp(0)';
            }

            if ($type === 'numeric' && $column->num_precision !== null) {
                $type = 'numeric('.$column->num_precision.','.$column->num_scale.')';
            }

            $types[$column->name] = $type;
        }

        $this->assertSame('numeric(12,0)', $types['PO_HEADER_ID']);
        $this->assertSame('timestamp(0)', $types['ORDER_DATE']);
    }

    public function test_status_sync_tercatat_di_dua_sisi(): void
    {
        $dataset = $this->dataset();
        $targetB = $this->pgB('armors');

        app(SyncRunner::class)->run($dataset);
        app(CopyEngine::class)->run($dataset, $targetB, $this->startCopyRun($dataset, $targetB));

        $inA = $this->pgConnection($dataset->target)->selectOne(
            'SELECT * FROM "_sync"."status" WHERE target_kind = ? AND dataset_key = ?::uuid',
            ['pg_a', $dataset->uid],
        );

        $inB = $this->pgConnection($targetB)->selectOne(
            'SELECT * FROM "_sync"."status" WHERE target_kind = ? AND app_name = ? AND dataset_key = ?::uuid',
            ['pg_b', 'armors', $dataset->uid],
        );

        $this->assertNotNull($inA);
        $this->assertNotNull($inB);

        // Aplikasi bisa menampilkan "data terakhir diperbarui pukul ...".
        $this->assertNotNull($inB->last_success_at);
        $this->assertSame(5, (int) $inB->row_count);
        $this->assertSame($inA->content_hash, $inB->content_hash);
    }

    public function test_copy_ulang_memperbaiki_pg_b_yang_rusak(): void
    {
        $dataset = $this->dataset();
        $targetB = $this->pgB('armors');

        app(SyncRunner::class)->run($dataset);
        app(CopyEngine::class)->run($dataset, $targetB, $this->startCopyRun($dataset, $targetB));

        // PG B rusak: tabel dihapus dan diisi data ngawur.
        $connectionB = $this->pgConnection($targetB);
        $connectionB->statement('DROP TABLE "APPS"."PO_HEADERS"');
        $connectionB->statement('CREATE TABLE "APPS"."PO_HEADERS" ("NGGAWUR" text)');
        $connectionB->statement("INSERT INTO \"APPS\".\"PO_HEADERS\" VALUES ('sampah')");

        $this->assertCount(1, $this->fetchTable($targetB, 'APPS', 'PO_HEADERS'));

        // Copy ulang dari A: B kembali benar tanpa menyentuh Oracle.
        $result = app(CopyEngine::class)->run($dataset, $targetB, $this->startCopyRun($dataset, $targetB));

        $this->assertTrue((bool) $result['verification']['matched']);
        $this->assertCount(5, $this->fetchTable($targetB, 'APPS', 'PO_HEADERS'));
        $this->assertContains('PO_HEADERS', $this->tableNames($targetB, 'APPS'));
    }

    public function test_copy_menulis_riwayat_run(): void
    {
        $dataset = $this->dataset();
        $targetB = $this->pgB('armors');

        app(SyncRunner::class)->run($dataset);
        $run = $this->startCopyRun($dataset, $targetB);

        app(CopyEngine::class)->run($dataset, $targetB, $run);

        $run->forceFill([
            'status' => RunStatusEnum::Success,
            'finished_at' => now(),
            'row_count' => 5,
        ])->save();

        $this->assertDatabaseHas('runs', [
            'id' => $run->id,
            'kind' => RunKindEnum::Copy->value,
            'status' => RunStatusEnum::Success->value,
        ]);
    }

    public function test_kill_switch_menahan_copy(): void
    {
        $dataset = $this->dataset();
        $targetB = $this->pgB('armors');

        app(SyncRunner::class)->run($dataset);

        app(\App\Services\Guard\KillSwitch::class)->pause();

        $this->expectException(\App\Services\Source\Exceptions\SyncException::class);

        try {
            app(CopyEngine::class)->run($dataset, $targetB, $this->startCopyRun($dataset, $targetB));
        } finally {
            app(\App\Services\Guard\KillSwitch::class)->resume();
        }
    }

    public function test_user_read_only_hanya_bisa_membaca_pg_b(): void
    {
        $dataset = $this->dataset();
        $targetB = $this->pgB('armors');

        $targetB->update(['readonly_username' => env('TEST_PG_RO_USER', 'ods_ro')]);

        app(SyncRunner::class)->run($dataset);
        app(CopyEngine::class)->run($dataset, $targetB, $this->startCopyRun($dataset, $targetB));

        // granting read-only untuk user aplikasi
        app(\App\Services\ReadOnly\ReadOnlyProvisioner::class)->grantSelect(
            $this->pgConnection($targetB),
            $targetB->fresh(),
            'APPS',
        );

        $roPdo = $this->readOnlyConnection($targetB);

        // Boleh membaca.
        $count = $roPdo->query('SELECT COUNT(*) FROM "APPS"."PO_HEADERS"')->fetchColumn();
        $this->assertSame(5, (int) $count);

        // Tidak boleh menulis.
        foreach (['DELETE', 'INSERT', 'UPDATE', 'TRUNCATE'] as $statement) {
            try {
                $sql = match ($statement) {
                    'DELETE' => 'DELETE FROM "APPS"."PO_HEADERS"',
                    'INSERT' => 'INSERT INTO "APPS"."PO_HEADERS" ("PO_NUMBER") VALUES (\'x\')',
                    'UPDATE' => 'UPDATE "APPS"."PO_HEADERS" SET "PO_NUMBER" = \'x\'',
                    'TRUNCATE' => 'TRUNCATE TABLE "APPS"."PO_HEADERS"',
                };

                $roPdo->exec($sql);

                $this->fail("User read-only seharusnya tidak bisa {$statement}.");
            } catch (\PDOException $exception) {
                $this->assertStringContainsString(
                    'permission denied',
                    strtolower($exception->getMessage()),
                    "Harapan: {$statement} ditolak karena tidak ada hak."
                );
            }
        }
    }

    private function readOnlyConnection(Target $target): \PDO
    {
        return new \PDO(
            sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=prefer',
                $target->host,
                $target->port,
                $target->database,
            ),
            env('TEST_PG_RO_USER', 'ods_ro'),
            env('TEST_PG_RO_PASSWORD', 'ods_ro_pass'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    private function startCopyRun(\App\Models\Dataset $dataset, Target $target): Run
    {
        return Run::create([
            'dataset_id' => $dataset->id,
            'target_id' => $target->id,
            'source_target_id' => $dataset->target_id,
            'kind' => RunKindEnum::Copy,
            'trigger' => \App\Enums\RunTriggerEnum::Manual,
            'status' => RunStatusEnum::Running,
            'started_at' => now(),
        ]);
    }
}
