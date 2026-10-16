<?php

namespace App\Jobs;

use App\Enums\RunTriggerEnum;
use App\Models\Dataset;
use App\Models\Target;
use App\Services\Copy\CopyRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Menyalin satu tabel dari PostgreSQL A ke PostgreSQL B.
 */
class CopyTableJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $datasetId,
        public readonly int $targetId,
        public readonly string $trigger = RunTriggerEnum::Manual->value,
        public readonly ?int $userId = null,
    ) {
    }

    /**
     * @return array<int,object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->datasetId.':'.$this->targetId))
                ->expireAfter(3600)
                ->dontRelease(),
        ];
    }

    public function handle(CopyRunner $runner): void
    {
        $dataset = Dataset::with(['target'])->find($this->datasetId);
        $destination = Target::find($this->targetId);

        if ($dataset === null || $destination === null) {
            return;
        }

        $runner->run(
            $dataset,
            $destination,
            RunTriggerEnum::from($this->trigger),
            $this->userId,
        );
    }
}
