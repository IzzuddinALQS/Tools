<?php

namespace Tests\Feature;

use App\Enums\RunStatusEnum;
use App\Services\Guard\DatasetLock;
use App\Services\Guard\KillSwitch;
use App\Services\Sync\SyncRunner;

/**
 * Keamanan & ketahanan: tool tidak boleh merusak data yang sudah ada,
 * tidak boleh crash saat Oracle mati, dan tidak boleh bocorkan kredensial.
 */
class ResilienceTest extends SyncTestCase
{
    public function test_gagal_di_tengah_tidak_menghapus_data_lama(): void
    {
        $dataset = $this->dataset();

        // Sync pertama berhasil: ada data lama yang harus dijaga.
        $first = app(SyncRunner::class)->run($dataset);
        $this->assertSame(RunStatusEnum::Success, $first->status);

        $before = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');
        $this->assertCount(5, $before);

        // Sync kedua sengaja gagal: batas baris terlampaui, jadi tool berhenti
        // dengan pesan jelas alih-alih menulis tabel tidak lengkap.
        $dataset->update(['max_rows' => 2]);

        $second = app(SyncRunner::class)->run($dataset->fresh());

        $this->assertSame(RunStatusEnum::Failed, $second->status);
        $this->assertSame('max_rows_exceeded', $second->error_code);

        // Data lama MASIH UTUH.
        $after = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');

        $this->assertCount(5, $after);
        $this->assertEquals(
            array_map(fn ($r) => (array) $r, $before),
            array_map(fn ($r) => (array) $r, $after),
        );

        // Tidak ada sisa tabel staging.
        $this->assertSame(['PO_HEADERS'], $this->tableNames($dataset->target, 'APPS'));
    }

    public function test_gagal_tidak_memperbarui_last_success_at(): void
    {
        $dataset = $this->dataset();
        $dataset->update(['max_rows' => 2]);

        $run = app(SyncRunner::class)->run($dataset);

        $this->assertSame(RunStatusEnum::Failed, $run->status);
        $this->assertNull($dataset->fresh()->last_success_at);
    }

    public function test_dataset_tidak_berjalan_ganda(): void
    {
        $dataset = $this->dataset();

        // Simulasikan proses lain sedang memegang lock.
        $lock = app(DatasetLock::class);
        $handle = $lock->acquire($lock->datasetKey($dataset->uid));

        $this->assertNotNull($handle);
        $this->assertTrue($handle->get(), 'Lock harus bisa didapat untuk simulasi proses lain.');

        try {
            $run = app(SyncRunner::class)->run($dataset);

            $this->assertSame(RunStatusEnum::Skipped, $run->status);
            $this->assertSame('already_running', $run->error_code);

            // Tidak ada tabel yang ditulis.
            $this->assertSame([], $this->tableNames($dataset->target, 'APPS'));
        } finally {
            $handle->release();
        }
    }

    public function test_oracle_tidak_bisa_dihubungi_tidak_membuat_tool_crash(): void
    {
        $connection = $this->unreachableOracleConnection();

        $dataset = $this->dataset([
            'connection_id' => $connection->id,
        ], $connection);

        $run = app(SyncRunner::class)->run($dataset);

        $this->assertSame(RunStatusEnum::Failed, $run->status);
        $this->assertSame('source_unavailable', $run->error_code);
        $this->assertNotEmpty($run->error_message);

        // Pesan harus rapi, tidak boleh membocorkan kredensial.
        $this->assertStringNotContainsString('super-secret-value', $run->error_message);
        $this->assertStringNotContainsString('super-secret-value', (string) json_encode($run->toArray()));
    }

    public function test_oracle_mati_tidak_menghapus_data_lama_yang_sudah_ada(): void
    {
        $dataset = $this->dataset();
        app(SyncRunner::class)->run($dataset);

        $before = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');
        $this->assertCount(5, $before);

        // Ganti sumber ke koneksi Oracle yang mati.
        $dataset->update(['connection_id' => $this->unreachableOracleConnection()->id]);

        $run = app(SyncRunner::class)->run($dataset->fresh());

        $this->assertSame(RunStatusEnum::Failed, $run->status);

        $after = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');
        $this->assertCount(5, $after);
    }

    public function test_kill_switch_menahan_seluruh_penulisan(): void
    {
        $dataset = $this->dataset();

        app(KillSwitch::class)->pause(null, 'Uji coba pemeliharaan');

        $run = app(SyncRunner::class)->run($dataset);

        $this->assertSame(RunStatusEnum::Paused, $run->status);
        $this->assertSame('global_paused', $run->error_code);

        // Tidak ada satu pun tabel yang dibuat.
        $this->assertSame([], $this->tableNames($dataset->target, 'APPS'));

        // Setelah di-resume, sinkronisasi jalan lagi.
        app(KillSwitch::class)->resume();

        $second = app(SyncRunner::class)->run($dataset);

        $this->assertSame(RunStatusEnum::Success, $second->status);
        $this->assertContains('PO_HEADERS', $this->tableNames($dataset->target, 'APPS'));
    }

    public function test_kill_switch_state_tersimpan_di_database(): void
    {
        $killSwitch = app(KillSwitch::class);

        $this->assertFalse($killSwitch->isPaused());

        $killSwitch->pause(null, 'Maintenance');

        $this->assertTrue($killSwitch->isPaused());

        // State harus ada di tabel settings, bukan hanya di memori.
        $this->assertDatabaseHas('settings', ['key' => 'global_pause']);

        $killSwitch->resume();

        $this->assertFalse($killSwitch->isPaused());
    }

    public function test_password_koneksi_terenkripsi_di_database(): void
    {
        $connection = $this->mockConnection();

        $raw = \Illuminate\Support\Facades\DB::table('connections')
            ->where('id', $connection->id)
            ->value('password');

        $this->assertNotSame('mock-secret', $raw);
        $this->assertStringNotContainsString('mock-secret', (string) $raw);

        // Tapi model membacanya kembali dengan benar.
        $this->assertSame('mock-secret', $connection->fresh()->password);
    }

    public function test_password_tidak_pernah_dikembalikan_ke_api(): void
    {
        $connection = $this->mockConnection();
        $target = $this->pgB();
        $target->update(['password' => 'target-secret', 'readonly_password' => 'ro-secret']);

        $connectionArray = $connection->toArray();
        $targetArray = $target->fresh()->toArray();

        $this->assertArrayNotHasKey('password', $connectionArray);
        $this->assertArrayNotHasKey('password', $targetArray);
        $this->assertArrayNotHasKey('readonly_password', $targetArray);
    }

    public function test_target_password_terenkripsi_di_database(): void
    {
        $target = $this->pgB();
        $target->update(['password' => 'target-secret']);

        $raw = \Illuminate\Support\Facades\DB::table('targets')
            ->where('id', $target->id)
            ->value('password');

        $this->assertStringNotContainsString('postgres', (string) $raw);
        $this->assertNotNull($target->fresh()->password);
    }
}
