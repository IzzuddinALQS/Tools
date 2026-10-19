<?php

namespace Tests\Feature;

use App\Enums\RunKindEnum;
use App\Enums\RunStatusEnum;
use App\Enums\RunTriggerEnum;
use App\Jobs\SyncDatasetJob;
use App\Models\Dataset;
use App\Models\Setting;
use App\Services\Api\RuntimeStatus;
use App\Services\Sync\SyncRunner;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Feature\SyncTestCase;
use Tests\Support\InteractsWithDatabases;

/**
 * Uji perilaku runtime yang terlihat dari sisi pemanggil API.
 *
 * U1: apakah sinkronisasi sedang berjalan
 * U3: apakah ada worker yang hidup
 * U4: apakah kegagalan job meninggalkan jejak
 * U6: apakah rantai sync -> copy benar-benar jalan dari pemicu API
 */
class RuntimeStatusTest extends SyncTestCase
{
    use InteractsWithDatabases;

    private function startRun(Dataset $dataset, RunStatusEnum $status, $startedAt): void
    {
        $dataset->runs()->create([
            'connection_id' => $dataset->connection_id,
            'target_id' => $dataset->target_id,
            'kind' => RunKindEnum::Sync,
            'trigger' => RunTriggerEnum::Schedule,
            'status' => $status,
            'sql_text' => $dataset->sql,
            'started_at' => $startedAt,
        ]);
    }

    public function test_dataset_terdeteksi_sedang_berjalan(): void
    {
        $dataset = $this->dataset();

        $this->assertFalse(RuntimeStatus::isDatasetRunning($dataset));

        $this->startRun($dataset, RunStatusEnum::Running, now());

        $this->assertTrue(RuntimeStatus::isDatasetRunning($dataset->fresh()));
    }

    public function test_run_yatim_tidak_menahan_dataset_selamanya(): void
    {
        $dataset = $this->dataset();

        /*
         * Run yang masih `running` tapi sudah jauh melewati batas waktu. Ini
         * yang terjadi kalau worker mati mendadak sehingga finished_at tidak
         * pernah terisi.
         */
        $this->startRun(
            $dataset,
            RunStatusEnum::Running,
            now()->subSeconds((int) $dataset->timeout_seconds + 3600),
        );

        $this->assertFalse(
            RuntimeStatus::isDatasetRunning($dataset->fresh()),
            'Run yatim tidak boleh membuat dataset terlihat berjalan terus.',
        );
    }

    public function test_run_yang_sudah_selesai_tidak_terhitung_berjalan(): void
    {
        $dataset = $this->dataset();

        $this->startRun($dataset, RunStatusEnum::Success, now());

        $this->assertFalse(RuntimeStatus::isDatasetRunning($dataset->fresh()));
    }

    public function test_worker_belum_pernah_ada_heartbeat_dianggap_tidak_diketahui(): void
    {
        $status = RuntimeStatus::workerStatus();

        $this->assertFalse($status['known']);
        $this->assertTrue(
            $status['alive'],
            'Tanpa heartbeat, instalasi tanpa scheduler tidak boleh ikut ditolak.',
        );
    }

    public function test_heartbeat_segera_dinyatakan_hidup(): void
    {
        RuntimeStatus::recordHeartbeat('sync');

        $status = RuntimeStatus::workerStatus();

        $this->assertTrue($status['known']);
        $this->assertTrue($status['alive']);
    }

    public function test_heartbeat_basi_dinyatakan_mati(): void
    {
        Setting::put(RuntimeStatus::WORKER_HEARTBEAT, [
            'at' => now()->subSeconds(RuntimeStatus::HEARTBEAT_STALE_AFTER + 60)->toIso8601String(),
        ]);

        $status = RuntimeStatus::workerStatus();

        $this->assertTrue($status['known']);
        $this->assertFalse($status['alive']);
    }

    public function test_heartbeat_rusak_tidak_membikin_panik(): void
    {
        Setting::put(RuntimeStatus::WORKER_HEARTBEAT, ['at' => 'bukan-tanggal']);

        $status = RuntimeStatus::workerStatus();

        $this->assertFalse($status['known']);
        $this->assertTrue($status['alive']);
    }

    public function test_perintah_heartbeat_berjalan(): void
    {
        $this->artisan('ods:queue-heartbeat', ['--queue' => 'sync'])->assertSuccessful();

        $this->assertTrue(RuntimeStatus::workerStatus()['alive']);
    }

    public function test_kegagalan_di_luar_syncrunner_tetap_meninggalkan_jejak(): void
    {
        /*
         * $tries = 1, jadi job yang crash sebelum SyncRunner sempat membuat
         * baris run tadinya hilang tanpa jejak sama sekali — tidak muncul di
         * halaman Riwayat, hanya di queue:failed yang tidak dipantau.
         */
        $dataset = $this->dataset();

        $this->assertSame(0, $dataset->runs()->count());

        // Partial mock: run() meledak, tapi startRun() dan failRun() tetap
        // memakai implementasi asli.
        $runner = Mockery::mock(SyncRunner::class)->makePartial();
        $runner->shouldReceive('run')->andThrow(new RuntimeException('mesin mati mendadak'));

        $job = new SyncDatasetJob($dataset->id, RunTriggerEnum::Api->value);

        try {
            $job->handle($runner);
            $this->fail('Exception asli seharusnya diteruskan.');
        } catch (RuntimeException $exception) {
            $this->assertSame('mesin mati mendadak', $exception->getMessage());
        }

        $run = $dataset->runs()->latest('id')->first();

        $this->assertNotNull($run, 'Kegagalan job harus tercatat di tabel runs.');
        $this->assertSame(RunStatusEnum::Failed, $run->status);
        $this->assertSame('unexpected_error', $run->error_code);
        $this->assertNotNull($run->finished_at);
    }

    public function test_rantai_api_menjalankan_sync_lalu_copy(): void
    {
        /*
         * U6: dari pemicu API, job sync harus ikut menyalin ke PG B kalau ada
         * auto_copy_to. Sebelumnya tidak ada test yang membuktikannya.
         */
        $dataset = $this->dataset();
        $targetB = $this->pgB('armors');

        $dataset->forceFill(['auto_copy_to' => [$targetB->id]])->save();

        $job = new SyncDatasetJob($dataset->fresh()->id, RunTriggerEnum::Api->value);
        $job->handle(app(SyncRunner::class));

        $run = $dataset->fresh()->runs()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame(RunStatusEnum::Success, $run->status);

        $source = $this->fetchTable($dataset->target, 'APPS', 'PO_HEADERS');
        $copy = $this->fetchTable($targetB, 'APPS', 'PO_HEADERS');

        $this->assertCount(5, $copy);

        // Nama kolom tetap huruf besar: identifier di PostgreSQL di-quote.
        $this->assertSame(
            array_map(fn ($row) => (string) $row->PO_NUMBER, $source),
            array_map(fn ($row) => (string) $row->PO_NUMBER, $copy),
        );
    }

    public function test_token_hanya_melakukan_satu_kali_pencarian_per_request(): void
    {
        /*
         * U2: rate limiter dan middleware memakai cache per request yang sama,
         * jadi satu panggilan API hanya membaca tabel api_tokens sekali.
         */
        DB::enableQueryLog();

        try {
            \App\Models\ApiToken::query()->count();
            DB::flushQueryLog();

            \App\Services\Api\ApiTokenLookup::plainFrom(request());

            $this->assertSame(0, count(DB::getQueryLog()), 'Tidak ada yang dikirim ke database.');
        } finally {
            DB::disableQueryLog();
        }
    }
}