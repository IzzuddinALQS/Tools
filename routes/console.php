<?php

use App\Enums\RunTriggerEnum;
use App\Jobs\SyncDatasetJob;
use App\Models\Dataset;
use App\Support\CronExpression;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler
|--------------------------------------------------------------------------
| Dijalankan satu kali per menit oleh cron:
|
|   * * * * * cd /var/www/oracle-data-sync && php artisan schedule:run >> /dev/null 2>&1
|
| Queue worker terpisah:
|
|   php artisan queue:work --queue=sync --tries=1 --timeout=3600
*/

// Tick per dataset untuk jadwal cron masing-masing (default: tiap jam).
Schedule::call(function (): void {
    $now = now();

    Dataset::query()
        ->where('status', 'active')
        ->with(['connection', 'target'])
        ->each(function (Dataset $dataset) use ($now): void {
            $cron = $dataset->schedule_cron ?: config('oracle-sync.defaults.schedule_cron');

            if ($cron === null || ! CronExpression::matches($cron, $now)) {
                return;
            }

            SyncDatasetJob::dispatch(
                $dataset->id,
                RunTriggerEnum::Schedule->value,
            )->onQueue('sync');
        });
})->everyMinute()->name('sync-datasets');

// Rekonsiliasi FULL untuk dataset mode incremental. Tanpa ini, baris yang
// dihapus di Oracle tidak akan pernah hilang dari PostgreSQL A.
Schedule::command('oracle-sync:reconcile')
    ->cron((string) config('oracle-sync.defaults.reconcile_cron', '0 2 * * *'))
    ->name('reconcile-full');

/*
 * Tanda bahwa worker antrean hidup.
 *
 * Endpoint API menolak dengan 503 kalau tanda ini basi, karena job yang
 * dijadwalkan tidak akan pernah dikerjakan. Tanpa ini, pemanggil hanya bisa
 * mendapat 202 lalu tidak ada yang terjadi.
 */
Schedule::command('ods:queue-heartbeat', ['--queue' => 'sync'])
    ->everyMinute()
    ->withoutOverlapping()
    ->name('queue-worker-heartbeat');
