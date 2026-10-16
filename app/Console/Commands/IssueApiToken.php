<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Models\Dataset;
use App\Models\Target;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Terbitkan token API Tahap 2.
 *
 * Token hanya ditampilkan sekali di sini. Setelah itu hanya hash-nya yang
 * tersimpan, persis seperti password. Kalau hilang, jalankan ulang perintah ini
 * dan cabut token lama.
 */
class IssueApiToken extends Command
{
    protected $signature = 'ods:token
                            {app_name : Nama aplikasi, mis. armors}
                            {--dataset= : Nama dataset (wajib)}
                            {--name= : Label token, mis. laptop-budi}
                            {--rate-limit=10 : Batas permintaan per menit}
                            {--ip=* : Batasi ke alamat IP ini (boleh diulang)}';

    protected $description = 'Terbitkan token API untuk satu kombinasi target + dataset';

    public function handle(): int
    {
        $appName = $this->argument('app_name');
        $key = (string) $this->option('dataset');

        /*
         * `uid` bertipe uuid di PostgreSQL, jadi querying kolom itu dengan
         * nama biasa akan ditolak dengan "invalid input syntax for type uuid".
         * Karena itu uid hanya dicari kalau inputnya memang berbentuk UUID.
         */
        $dataset = Dataset::query()
            ->when(
                Str::isUuid($key),
                fn ($query) => $query->where('uid', $key),
                fn ($query) => $query->where('name', $key),
            )
            ->with('target')
            ->first();

        if ($dataset === null) {
            $this->components->error(
                'Dataset tidak ditemukan. Lihat daftar dengan: php artisan ods:token '.$appName.' --help'
            );
            $this->line('  Daftar dataset yang tersedia:');
            Dataset::query()->orderBy('name')->get()
                ->each(fn (Dataset $item) => $this->line('    - '.$item->name.' ('.$item->uid.')'));

            return self::FAILURE;
        }

        $target = $dataset->relationLoaded('target') ? $dataset->target : Target::find($dataset->target_id);

        if ($target === null) {
            $this->components->error('Target PostgreSQL A untuk dataset ini tidak ditemukan.');

            return self::FAILURE;
        }

        if ($dataset->status->value !== 'active') {
            $this->components->warn('Dataset ini sedang dijeda; token tetap diterbitkan, tapi refresh akan mengembalikan 409.');
        }

        [$token, $plain] = ApiToken::issue([
            'name' => $this->option('name') ?: ('token-'.$this->now()),
            'app_name' => $appName,
            'target_id' => $target->id,
            'dataset_id' => $dataset->id,
            'rate_limit_per_minute' => max(1, (int) $this->option('rate-limit')),
            'allowed_ips' => $this->option('ip') ?: null,
        ]);

        $this->newLine();
        $this->components->info('Token terbit.');
        $this->newLine();

        $this->components->twoColumnDetail('Aplikasi', $token->app_name);
        $this->components->twoColumnDetail('Target', $target->name.' ('.$target->uid.')');
        $this->components->twoColumnDetail('Dataset', $dataset->name.' ('.$dataset->uid.')');
        $this->components->twoColumnDetail('Batas laju', $token->rate_limit_per_minute.' / menit');

        if ($token->allowed_ips !== null) {
            $this->components->twoColumnDetail('IP diizinkan', implode(', ', $token->allowed_ips));
        }

        $this->newLine();
        $this->line('  <fg=yellow>Simpan sekarang. Token tidak akan ditampilkan lagi.</>');
        $this->newLine();
        $this->line('  <fg=green>'.$plain.'</>');
        $this->newLine();

        $base = rtrim((string) config('app.url'), '/');
        $this->line('  URL:');
        $this->line('  <fg=cyan>POST '.$base.'/api/v1/targets/'.$target->uid.'/datasets/'.$dataset->uid.'/refresh</>');
        $this->line('  <fg=gray>Header: Authorization: Bearer <token></>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function now(): string
    {
        return now()->format('Ymd-His');
    }
}