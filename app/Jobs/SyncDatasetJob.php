<?php

namespace App\Jobs;

use App\Enums\RunKindEnum;
use App\Enums\RunTriggerEnum;
use App\Models\Dataset;
use App\Services\Sync\SyncRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menjalankan sinkronisasi satu dataset di luar request HTTP.
 *
 * `WithoutOverlapping` adalah lapis kedua; lapis pertama ada di SyncRunner
 * (Cache lock) supaya berlaku juga untuk pemicu dari scheduler dan CLI.
 */
class SyncDatasetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  array<int,string>  $tags
     */
    public function __construct(
        public readonly int $datasetId,
        public readonly string $trigger = RunTriggerEnum::Manual->value,
        public readonly ?int $userId = null,
        public readonly bool $thenCopy = true,
        public readonly array $tags = [],
    ) {
    }

    /**
     * @return array<int,object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->datasetKey()))->expireAfter(3600)->dontRelease(),
        ];
    }

    public function datasetKey(): string
    {
        return 'dataset-id-'.$this->datasetId;
    }

    public function handle(SyncRunner $runner): void
    {
        $dataset = Dataset::with(['connection', 'target'])->find($this->datasetId);

        if ($dataset === null) {
            return;
        }

        /*
         * Semua kegagalan harus meninggalkan jejak di tabel runs.
         *
         * `$tries = 1`, jadi job yang crash sebelum SyncRunner sempat
         * membuat baris run — OOM, timeout keras, kill dari luar — tadinya
         * hilang tanpa jejak sama sekali: tidak ada di halaman Riwayat,
         * hanya di queue:failed yang tidak dipantau siapa pun.
         *
         * Di sini dibungkus supaya baris run selalu ada, apa pun yang terjadi.
         */
        try {
            $run = $runner->run(
                $dataset,
                RunTriggerEnum::from($this->trigger),
                RunKindEnum::Sync,
                $this->userId,
            );
        } catch (Throwable $exception) {
            $this->recordUncaughtFailure($dataset, $exception);

            throw $exception;
        }

        // Copy otomatis ke PG B hanya bila sync benar-benar sukses.
        if (! $this->thenCopy || $dataset->status->value !== 'active') {
            return;
        }

        if ($run->status->value !== 'success') {
            return;
        }

        foreach ((array) $dataset->auto_copy_to as $targetId) {
            // Ikuti queue yang sama dengan job ini. Kalau CopyTableJob
            // dikirim ke queue default, copy otomatis tertinggal bila worker
            // hanya menjalankan queue `sync`.
            CopyTableJob::dispatch($dataset->id, (int) $targetId, $this->trigger, $this->userId)
                ->onQueue($this->queue ?: 'sync');
        }
    }

    /**
     * Catat kegagalan yang belum tertangkap SyncRunner.
     *
     * Pesannya dibersihkan lewat SyncRunner supaya tidak ada kredensial yang
     * bocor ke riwayat.
     */
    private function recordUncaughtFailure(Dataset $dataset, Throwable $exception): void
    {
        try {
            // Di-resolve dari container, bukan diteruskan sebagai parameter.
            // Parameternya cuma exception, dan kelalaian resolved seperti ini
            // akan tertangkap catch di bawah — persis bug yang membuat
            // kegagalan tidak tercatat sama sekali.
            $runner = app(SyncRunner::class);

            $run = $runner->startRun(
                $dataset,
                RunTriggerEnum::from($this->trigger),
                RunKindEnum::Sync,
                $this->userId,
            );

            $runner->failRun($run, $exception);
        } catch (Throwable) {
            // Kalau bahkan mencatat pun gagal (mis. database ikut tumbang),
            // tidak ada yang bisa dilakukan di sini. Jangan menimpa exception
            // aslinya dengan error pencatatan.
            Log::error('Gagal mencatat kegagalan SyncDatasetJob.', [
                'dataset' => $dataset->id,
                'trigger' => $this->trigger,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Dipanggil Laravel saat job gagal permanen.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('SyncDatasetJob gagal permanen.', [
            'dataset_id' => $this->datasetId,
            'trigger' => $this->trigger,
            'message' => $exception?->getMessage(),
        ]);
    }
}
