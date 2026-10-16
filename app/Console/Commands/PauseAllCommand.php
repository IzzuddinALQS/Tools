<?php

namespace App\Console\Commands;

use App\Services\Guard\KillSwitch;
use Illuminate\Console\Command;

/**
 * Kill switch global (Keamanan 7).
 */
class PauseAllCommand extends Command
{
    protected $signature = 'oracle-sync:pause
        {--resume : Nyalakan lagi semua job}
        {--reason= : Alasan pause, ditampilkan di UI}
        {--user= : ID user yang melakukan perubahan}';

    protected $description = 'Pause / resume semua job sinkronisasi (kill switch).';

    public function handle(KillSwitch $killSwitch): int
    {
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;

        if ($this->option('resume')) {
            $killSwitch->resume($userId);
            $this->info('Semua job di-resume.');

            return self::SUCCESS;
        }

        $killSwitch->pause($userId, $this->option('reason'));
        $this->warn('Semua job di-PAUSE. Tidak ada penulisan ke PostgreSQL A atau B.');

        return self::SUCCESS;
    }
}
