<?php

namespace App\Console\Commands;

use App\Services\Api\RuntimeStatus;
use Illuminate\Console\Command;

/**
 * Tandai bahwa ada worker antrean yang hidup.
 *
 * Endpoint API menolak dengan 503 kalau heartbeat ini basi, karena job yang
 * dijadwalkan tidak akan pernah dikerjakan. Tanpa tanda ini, pemanggil hanya
 * bisa mendapat 202 lalu tidak ada yang terjadi.
 *
 * Dijalankan dari scheduler setiap menit, bukan dari dalam worker, supaya
 * satu proses yang jarang restart tetap menghasilkan tanda yang segar.
 */
class RecordQueueHeartbeat extends Command
{
    protected $signature = 'ods:queue-heartbeat {--queue=sync : Antrean yang dipantau}';

    protected $description = 'Catat tanda bahwa worker antrean aktif';

    public function handle(): int
    {
        $queue = (string) $this->option('queue');

        RuntimeStatus::recordHeartbeat($queue);

        $this->components->info('Heartbeat worker antrean dicatat (queue: '.$queue.').');

        return self::SUCCESS;
    }
}