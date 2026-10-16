<?php

namespace App\Console\Commands;

use App\Enums\RunKindEnum;
use App\Enums\RunTriggerEnum;
use App\Models\Dataset;
use App\Services\Sync\SyncRunner;
use Illuminate\Console\Command;

/**
 * Menjalankan sinkronisasi dataset (juga dipakai scheduler).
 */
class SyncDatasetCommand extends Command
{
    protected $signature = 'oracle-sync:sync
        {dataset? : ID atau nama dataset}
        {--all : Semua dataset aktif}
        {--full : Paksa mode full, dipakai untuk rekonsiliasi}
        {--trigger=schedule : Pemicu yang dicatat di riwayat}';

    protected $description = 'Jalankan sinkronisasi Oracle ke PostgreSQL A.';

    public function handle(SyncRunner $runner): int
    {
        $query = Dataset::query()->with(['connection', 'target']);

        if ($this->option('all')) {
            $datasets = $query->where('status', 'active')->get();
        } else {
            $identifier = $this->argument('dataset');

            if ($identifier === null) {
                $this->error('Sebutkan dataset, atau gunakan --all.');

                return self::FAILURE;
            }

            $dataset = $query
                ->where('name', $identifier)
                ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0)
                ->first();

            if ($dataset === null) {
                $this->error('Dataset tidak ditemukan: '.$identifier);

                return self::FAILURE;
            }

            $datasets = collect([$dataset]);
        }

        $trigger = RunTriggerEnum::tryFrom((string) $this->option('trigger')) ?? RunTriggerEnum::Schedule;

        foreach ($datasets as $dataset) {
            if ($this->option('full')) {
                $kind = RunKindEnum::Reconcile;
            } else {
                $kind = RunKindEnum::Sync;
            }

            $run = $runner->run($dataset, $trigger, $kind);

            $this->line(sprintf(
                '[%s] dataset=%s rows=%s verifikasi=%s',
                $run->status->value,
                $dataset->name,
                $run->row_count ?? '-',
                $run->verificationBadge() ?? '-',
            ));

            if ($run->status->value === 'failed') {
                $this->error('  '.$run->error_message);
            }
        }

        return self::SUCCESS;
    }
}
