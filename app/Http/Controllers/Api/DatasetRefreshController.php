<?php

namespace App\Http\Controllers\Api;

use App\Enums\DatasetStatusEnum;
use App\Enums\RunTriggerEnum;
use App\Jobs\SyncDatasetJob;
use App\Models\ApiToken;
use App\Models\Dataset;
use App\Models\Target;
use App\Services\Api\RuntimeStatus;
use App\Services\Guard\KillSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API Tahap 2: aplikasi eksternal meminta "sinkron sekarang".
 *
 * Bentuk URL memakai UUID, bukan id berurutan:
 *
 *     POST /api/v1/targets/{target_uid}/datasets/{dataset_uid}/refresh
 *
 * Endpoint ini HANYA bisa memicu dataset yang sudah terdaftar. Tidak ada
 * parameter SQL sama sekali, jadi tidak ada jalur untuk menyuntikkan query
 * dari luar. SQL dan pemetaan kolom sudah tersimpan dari versi dataset yang
 * disetujui admin.
 */
class DatasetRefreshController
{
    public function __invoke(
        Request $request,
        string $target,
        string $dataset,
        KillSwitch $killSwitch,
    ): JsonResponse {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        $targetModel = Target::query()->where('uid', $target)->first();
        $datasetModel = Dataset::query()->with(['connection', 'target'])->where('uid', $dataset)->first();

        if ($targetModel === null || $datasetModel === null) {
            return $this->error('Target atau dataset tidak ditemukan.', 404);
        }

        /*
         * Token hanya berlaku untuk satu kombinasi target + dataset.
         *
         * Tanpa cek ini, aplikasi ARMORS bisa memicu dataset milik aplikasi
         * lain hanya dengan menebak UUID-nya. Pesan sengaja disamakan untuk
         * kombinasi yang tidak dikenal dan yang tidak berwenang, supaya tidak
         * bisa dipakai memetakan isi sistem.
         */
        if ($token->target_id !== $targetModel->id || $token->dataset_id !== $datasetModel->id) {
            return $this->error('Token ini tidak berwenang untuk target/dataset tersebut.', 403);
        }

        if ($datasetModel->target_id !== $targetModel->id) {
            return $this->error('Dataset ini menulis ke PostgreSQL A yang berbeda.', 409);
        }

        $token->markUsed();

        if ($killSwitch->isPaused()) {
            return response()->json([
                'message' => 'Semua job sedang di-pause. Tidak ada penulisan.',
                'status' => 'paused',
                'dataset' => $datasetModel->name,
            ], 409);
        }

        if ($datasetModel->status !== DatasetStatusEnum::Active) {
            return response()->json([
                'message' => 'Dataset ini sedang dijeda.',
                'status' => 'skipped',
                'dataset' => $datasetModel->name,
            ], 409);
        }

        /*
         * Sudah ada sinkronisasi yang berjalan?
         *
         * Diperiksa lewat tabel runs, bukan cache lock — cache lock tidak bisa
         * dicek tanpa mengambilnya, dan mengambilnya justru akan mengunci
         * dataset yang sedang bebas.
         *
         * 200 (bukan 409), karena permintaan sebenarnya sudah terpenuhi oleh
         * proses yang sedang berjalan.
         */
        if (RuntimeStatus::isDatasetRunning($datasetModel)) {
            return response()->json([
                'message' => 'Sinkronisasi sedang berjalan.',
                'status' => 'skipped',
                'dataset' => $datasetModel->name,
                'last_success_at' => $datasetModel->last_success_at?->toIso8601String(),
            ], 200);
        }

        /*
         * Worker-nya hidup?
         *
         * Tanpa ini, 202 di bawah berbohong: job-nya akan menggantung di
         * tabel jobs selamanya sementara pemanggil sudah diberi tahu
         * "dijadwalkan". `known: false` berarti belum pernah ada heartbeat,
         * jadi statusnya tidak boleh diasumsikan mati.
         */
        $worker = RuntimeStatus::workerStatus();

        if ($worker['known'] && ! $worker['alive']) {
            return response()->json([
                'message' => 'Tidak ada worker antrean yang aktif. Permintaan tidak jadi dijadwalkan. Jalankan: php artisan queue:work --queue=sync',
                'status' => 'worker_unavailable',
                'last_heartbeat' => $worker['at'],
                'last_heartbeat_age_seconds' => $worker['age'],
            ], 503);
        }

        $token->markUsed();

        SyncDatasetJob::dispatch(
            $datasetModel->id,
            RunTriggerEnum::Api->value,
        )->onQueue('sync');

        return response()->json([
            'message' => 'Sinkronisasi dijadwalkan.',
            'status' => 'queued',
            'dataset' => $datasetModel->name,
            'trigger' => RunTriggerEnum::Api->value,
        ], 202);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}