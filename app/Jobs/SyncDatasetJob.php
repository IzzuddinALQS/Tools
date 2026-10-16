<?php

namespace App\Jobs;

use App\Enums\RunKindEnum;
use App\Enums\RunTriggerEnum;
use App\Models\Dataset;
use App\Services\Sync\SyncRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

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

        $run = $runner->run(
            $dataset,
            RunTriggerEnum::from($this->trigger),
            RunKindEnum::Sync,
            $this->userId,
        );

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
}
