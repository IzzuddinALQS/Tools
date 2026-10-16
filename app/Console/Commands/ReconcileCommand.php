<?php

namespace App\Console\Commands;

use App\Enums\RunKindEnum;
use App\Enums\RunTriggerEnum;
use App\Models\Dataset;
use App\Services\Sync\SyncRunner;
use Illuminate\Console\Command;

/**
 * Rekonsiliasi `full` untuk dataset incremental (wajib, Aturan mode sync).
 *
 * Mode incremental tidak mendeteksi baris yang dihapus di Oracle, jadi
 * jadwal full berkala wajib menyetel ulang PG A agar identik lagi.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'oracle-sync:reconcile {--dry-run : Tampilkan daftar tanpa menjalankan}';

    protected $description = 'Jalankan sinkronisasi FULL untuk semua dataset mode incremental.';

    public function handle(SyncRunner $runner): int
    {
        $datasets = Dataset::query()
            ->with(['connection', 'target'])
            ->where('sync_mode', 'incremental')
            ->where('status', 'active')
            ->get();

        if ($datasets->isEmpty()) {
            $this->info('Tidak ada dataset mode incremental yang aktif.');

            return self::SUCCESS;
        }

        foreach ($datasets as $dataset) {
            if ($this->option('dry-run')) {
                $this->line('- '.$dataset->name.' -> '.$dataset->qualifiedDestination());

                continue;
            }

            // Rekonsiliasi sengaja memaksa mode FULL meski dataset-nya
            // incremental, dan ditulis sebagai run terpisah agar bisa
            // dibedakan di riwayat.
            $run = $runner->run(
                $dataset,
                RunTriggerEnum::Reconcile,
                RunKindEnum::Reconcile,
                null,
                true,
            );

            $this->line(sprintf(
                '[%s] %s rows=%s verifikasi=%s',
                $run->status->value,
                $dataset->name,
                $run->row_count ?? '-',
                $run->verificationBadge() ?? '-',
            ));
        }

        return self::SUCCESS;
    }
}
