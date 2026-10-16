<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Command;

/**
 * Cabut token API Tahap 2.
 *
 * Token dicabut, bukan dihapus, supaya riwayat penaggunanya tetap bisa dibaca
 * saat menelusuri insiden.
 */
class RevokeApiToken extends Command
{
    protected $signature = 'ods:token-revoke
                            {token : UID token, atau pakai --all untuk seluruh token satu aplikasi}
                            {--app= : Batasi ke satu aplikasi}
                            {--all : Cabut semua token aktif milik aplikasi tersebut}
                            {--reason= : Alasan pencabutan}';

    protected $description = 'Cabut token API';

    public function handle(): int
    {
        $query = ApiToken::query()->active();

        if ($this->option('all')) {
            if (! $this->option('app')) {
                $this->components->error('Gunakan --app bersama --all, atau sebut UID token secara langsung.');

                return self::FAILURE;
            }

            $query->where('app_name', $this->option('app'));
        } else {
            $query->where('uid', $this->argument('token'));
        }

        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            $this->components->warn('Tidak ada token aktif yang cocok.');

            return self::FAILURE;
        }

        foreach ($tokens as $token) {
            $token->revoke($this->option('reason') ?: 'Dicabut via artisan');
        }

        $this->components->info($tokens->count().' token dicabut.');

        foreach ($tokens as $token) {
            $this->components->twoColumnDetail($token->uid, $token->app_name.' — '.$token->name);
        }

        return self::SUCCESS;
    }
}