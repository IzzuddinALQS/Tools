<?php

namespace App\Services\Copy;

use App\Enums\RunKindEnum;
use App\Enums\RunStatusEnum;
use App\Enums\RunTriggerEnum;
use App\Models\Dataset;
use App\Models\Run;
use App\Models\Target;
use App\Services\Guard\DatasetLock;
use App\Services\Source\Exceptions\SyncException;
use Throwable;

/**
 * Menjalankan copy A -> B sambil mencatat riwayat, dipakai bersama oleh
 * tombol Copy di UI dan oleh job sinkronisasi otomatis.
 */
class CopyRunner
{
    public function __construct(
        private readonly CopyEngine $engine,
        private readonly DatasetLock $lock,
    ) {
    }

    public function run(
        Dataset $dataset,
        Target $destination,
        RunTriggerEnum $trigger = RunTriggerEnum::Manual,
        ?int $userId = null,
    ): Run {
        $lock = $this->lock->acquire($this->lock->copyKey($dataset->uid, $destination->uid));

        if ($lock === null || ! $lock->get()) {
            return $this->skipped($dataset, $destination, $trigger, $userId);
        }

        $run = Run::create([
            'dataset_id' => $dataset->id,
            'target_id' => $destination->id,
            'source_target_id' => $dataset->target_id,
            'kind' => RunKindEnum::Copy,
            'trigger' => $trigger,
            'status' => RunStatusEnum::Running,
            'started_at' => now(),
            'triggered_by' => $userId,
        ]);

        try {
            $result = $this->engine->run($dataset, $destination, $run);

            $run->forceFill([
                'status' => RunStatusEnum::Success,
                'finished_at' => now(),
                'duration_ms' => $this->duration($run),
                'row_count' => $result['row_count'],
                'verification' => $result['verification'],
            ])->save();

            return $run;
        } catch (Throwable $exception) {
            $code = $exception instanceof SyncException ? $exception->errorCode() : 'copy_failed';

            $run->forceFill([
                'status' => $code === 'global_paused' ? RunStatusEnum::Paused : RunStatusEnum::Failed,
                'finished_at' => now(),
                'duration_ms' => $this->duration($run),
                'error_code' => $code,
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            return $run;
        } finally {
            $this->lock->release($lock);
        }
    }

    private function skipped(
        Dataset $dataset,
        Target $destination,
        RunTriggerEnum $trigger,
        ?int $userId,
    ): Run {
        return Run::create([
            'dataset_id' => $dataset->id,
            'target_id' => $destination->id,
            'source_target_id' => $dataset->target_id,
            'kind' => RunKindEnum::Copy,
            'trigger' => $trigger,
            'status' => RunStatusEnum::Skipped,
            'started_at' => now(),
            'finished_at' => now(),
            'error_code' => 'already_running',
            'error_message' => 'Copy ke "'.$destination->name.'" sedang berjalan di proses lain.',
            'triggered_by' => $userId,
        ]);
    }

    private function duration(Run $run): ?int
    {
        if ($run->started_at === null) {
            return null;
        }

        return (int) round((microtime(true) - $run->started_at->getTimestamp()) * 1000);
    }
}
