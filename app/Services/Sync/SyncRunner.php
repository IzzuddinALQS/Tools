<?php

namespace App\Services\Sync;

use App\Enums\RunKindEnum;
use App\Enums\RunStatusEnum;
use App\Enums\RunTriggerEnum;
use App\Models\Dataset;
use App\Models\Run;
use App\Services\Guard\DatasetLock;
use App\Services\Source\Exceptions\SyncException;
use App\Services\Sync\SyncEngine;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pembungkus eksekusi sync: mencatat Run, memegang lock, dan memastikan
 * kegagalan tidak pernah merusak data lama.
 */
class SyncRunner
{
    public function __construct(
        private readonly SyncEngine $engine,
        private readonly DatasetLock $lock,
    ) {
    }

    public function run(
        Dataset $dataset,
        RunTriggerEnum $trigger = RunTriggerEnum::Manual,
        RunKindEnum $kind = RunKindEnum::Sync,
        ?int $userId = null,
        bool $forceFull = false,
    ): Run {
        $run = $this->startRun($dataset, $trigger, $kind, $userId);

        $result = $this->lock->run(
            $this->lock->datasetKey($dataset->uid),
            fn () => $this->execute($dataset, $run, $forceFull),
        );

        /*
         * null HANYA berarti lock sedang dipegang proses lain. Kalau cache
         * store mati, DatasetLock sengaja tetap menjalankan callback-nya (bukan
         * null) supaya satu outage tidak menghentikan seluruh sinkronisasi.
         */
        if ($result === null) {
            $this->finishSkipped($run, 'Dataset ini sedang berjalan di proses lain.');

            return $run;
        }

        return $run->fresh() ?? $run;
    }

    private function execute(Dataset $dataset, Run $run, bool $forceFull = false): Run
    {
        try {
            $result = $this->engine->run($dataset, $run, $forceFull);

            $run->forceFill([
                'status' => RunStatusEnum::Success,
                'finished_at' => now(),
                'duration_ms' => $this->duration($run),
                'row_count' => $result->rowCount,
                'verification' => $result->verification,
                'sql_text' => $result->executedSql,
                'finished_at' => now(),
            ])->save();

            $dataset->forceFill([
                'last_run_id' => $run->id,
                'last_success_at' => now(),
                'last_row_count' => $result->rowCount,
            ])->save();

            return $run;
        } catch (Throwable $exception) {
            return $this->failRun($run, $exception);
        }
    }

    public function startRun(
        Dataset $dataset,
        RunTriggerEnum $trigger,
        RunKindEnum $kind,
        ?int $userId,
    ): Run {
        return Run::create([
            'dataset_id' => $dataset->id,
            'connection_id' => $dataset->connection_id,
            'target_id' => $dataset->target_id,
            'kind' => $kind,
            'trigger' => $trigger,
            'status' => RunStatusEnum::Running,
            'sql_text' => $dataset->sql,
            'sql_hash' => hash('sha256', (string) $dataset->sql),
            'started_at' => now(),
            'triggered_by' => $userId,
        ]);
    }

    public function failRun(Run $run, Throwable $exception): Run
    {
        $code = $exception instanceof SyncException ? $exception->errorCode() : 'unexpected_error';

        // Keamanan 6: pesan rapi, tanpa kredensial.
        $message = $this->sanitize($exception->getMessage(), $run);

        $run->forceFill([
            'status' => $this->statusFor($code),
            'finished_at' => now(),
            'duration_ms' => $this->duration($run),
            'error_code' => $code,
            'error_message' => $message,
        ])->save();

        Log::warning('Sync dataset gagal.', [
            'run_id' => $run->id,
            'dataset' => $run->dataset_id,
            'error_code' => $code,
        ]);

        return $run;
    }

    private function finishSkipped(Run $run, string $message): void
    {
        $run->forceFill([
            'status' => RunStatusEnum::Skipped,
            'finished_at' => now(),
            'duration_ms' => $this->duration($run),
            'error_code' => 'already_running',
            'error_message' => $message,
        ])->save();
    }

    private function statusFor(string $code): RunStatusEnum
    {
        return $code === 'global_paused' ? RunStatusEnum::Paused : RunStatusEnum::Failed;
    }

    private function duration(Run $run): ?int
    {
        if ($run->started_at === null) {
            return null;
        }

        return (int) round((microtime(true) - $run->started_at->getTimestamp()) * 1000);
    }

    /**
     * Buang apa pun yang menyerupai kredensial dari pesan error.
     */
    private function sanitize(string $message, Run $run): string
    {
        $message = (string) preg_replace(
            '/\b(?:password|passwd|pwd)\s*[=:]\s*\S+/i',
            'password=***',
            $message
        );

        return mb_substr(trim($message), 0, 1000);
    }
}
