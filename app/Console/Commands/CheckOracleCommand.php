<?php

namespace App\Console\Commands;

use App\Models\Connection;
use App\Services\Source\SourceReaderFactory;
use App\Services\Sync\PreviewService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pemeriksaan bertahap untuk orang yang pertama kali mencoba tool ini
 * dengan Oracle sungguhan.
 *
 * Setiap langkah berdiri sendiri dan menjelaskan apa yang harus dibetulkan
 * bila gagal, supaya masalah instalasi (oci8, Instant Client, hak akses)
 * bisa ditemukan tanpa membaca kode. Hanya membaca: semua query lewat
 * SourceReader, jadi tetap dijaga SelectGuard.
 */
class CheckOracleCommand extends Command
{
    protected $signature = 'ods:cek-oracle
        {connection? : ID atau nama koneksi Oracle (lihat halaman Koneksi)}
        {--tabel= : Contoh tabel untuk dicoba, mis. APPS.PO_HEADERS}
        {--mock : Coba perintah ini pada koneksi Data contoh (tanpa Oracle)}';

    protected $description = 'Periksa kesiapan server dan koneksi Oracle, langkah demi langkah.';

    private int $failures = 0;

    public function handle(SourceReaderFactory $readers, PreviewService $preview): int
    {
        $this->newLine();
        $this->line('<options=bold>1. Server PHP</>');

        $hasOci8 = extension_loaded('oci8');
        $this->check($hasOci8, 'Ekstensi PHP oci8 terpasang', 'Pasang oci8 sesuai README bagian "Instalasi oci8". Cek dengan: php -m | grep oci8');

        if ($hasOci8 && function_exists('oci_client_version')) {
            $this->info('     Oracle Instant Client versi '.oci_client_version());
        }

        $hasDriver = class_exists(\Yajra\Oci8\Oci8ServiceProvider::class);
        $this->check($hasDriver, 'Paket yajra/laravel-oci8 tersedia', 'Jalankan: composer install');

        if ((! $hasOci8 || ! $hasDriver) && ! $this->option('mock')) {
            return $this->finish();
        }

        $this->newLine();
        $this->line('<options=bold>2. Koneksi Oracle</>');

        $connection = $this->resolveConnection();

        if ($connection === null) {
            return $this->finish();
        }

        $reader = $readers->for($connection);

        try {
            $reader->test();
            $this->check(true, 'Terhubung dan bisa menjalankan SELECT 1 FROM DUAL');
        } catch (Throwable $exception) {
            $this->check(false, 'Terhubung ke Oracle', $exception->getMessage());

            return $this->finish();
        }

        try {
            $this->check(true, 'Schema bawaan sesi: '.$reader->defaultSchema()
                .' (dipakai bila query tidak menyebut schema)');
        } catch (Throwable $exception) {
            $this->check(false, 'Membaca schema bawaan sesi', $exception->getMessage());
        }

        $this->newLine();
        $this->line('<options=bold>3. Hak akses akun Oracle</>');
        $this->checkPrivileges($reader);

        $table = (string) $this->option('tabel');

        $this->newLine();
        $this->line('<options=bold>4. Coba baca tabel</>');

        if ($table === '') {
            $this->line('     (dilewati) Tambahkan --tabel=SKEMA.NAMA_TABEL untuk mencoba membaca data.');
        } else {
            $this->checkTable($preview, $reader, $table);
        }

        return $this->finish();
    }

    private function resolveConnection(): ?Connection
    {
        $argument = $this->argument('connection');

        $query = Connection::query()->where('driver', $this->option('mock') ? 'mock' : 'oracle');

        if ($argument !== null) {
            $query->where(fn ($q) => $q->where('name', $argument)
                ->orWhere('id', is_numeric($argument) ? (int) $argument : 0));
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            $this->check(false, 'Koneksi Oracle ditemukan', $argument === null
                ? 'Belum ada koneksi berjenis Oracle. Tambahkan dulu di halaman Koneksi → Sumber koneksi.'
                : "Tidak ada koneksi Oracle bernama/ber-ID \"{$argument}\".");

            return null;
        }

        if ($connections->count() > 1) {
            $this->check(false, 'Pilih satu koneksi', 'Ada beberapa koneksi Oracle: '
                .$connections->pluck('name')->implode(', ').'. Sebutkan salah satunya, mis. php artisan ods:cek-oracle '.$connections->first()->name);

            return null;
        }

        $connection = $connections->first();
        $this->info('     Memakai koneksi: '.$connection->safeLabel());

        return $connection;
    }

    private function checkPrivileges($reader): void
    {
        try {
            $privileges = [];

            foreach ($reader->stream('SELECT PRIVILEGE FROM SESSION_PRIVS', [], 500, 60)->rows() as $row) {
                $privileges[] = strtoupper((string) (array_values($row)[0] ?? ''));
            }
        } catch (Throwable $exception) {
            $this->warn('     ! Tidak bisa membaca SESSION_PRIVS: '.$exception->getMessage());

            return;
        }

        // Hak yang bisa mengubah data atau struktur. CREATE SESSION wajib ada.
        $risky = array_values(array_filter($privileges, fn (string $p) => $p !== 'CREATE SESSION'
            && preg_match('/\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|EXECUTE ANY|GRANT|BECOME|LOCK ANY|TRUNCATE)\b/', $p)));

        $this->check(
            $risky === [],
            'Akun tidak punya hak menulis tingkat sistem',
            'Akun ini punya hak: '.implode(', ', array_slice($risky, 0, 10))
                .'. Tool tetap tidak akan menulis, tetapi sebaiknya DBA memakai akun yang hanya punya SELECT.',
            warnOnly: true,
        );
    }

    private function checkTable(PreviewService $preview, $reader, string $table): void
    {
        if (! preg_match('/^[A-Za-z0-9_$#]+(\.[A-Za-z0-9_$#]+)?$/', $table)) {
            $this->check(false, 'Nama tabel valid', 'Tulis seperti SKEMA.NAMA_TABEL, tanpa spasi atau tanda kutip.');

            return;
        }

        try {
            $result = $preview->preview('SELECT * FROM '.$table, $reader, limit: 5);
        } catch (Throwable $exception) {
            $this->check(false, "Membaca {$table}", $exception->getMessage());

            return;
        }

        $this->check(true, "Membaca {$table}: ".count($result['columns']).' kolom, '
            .$result['row_count_shown'].' baris contoh');

        $this->check(
            (bool) $result['identity_mode'],
            'Tipe kolom diambil dari metadata Oracle (ALL_TAB_COLUMNS)',
            'Metadata tabel tidak terbaca, jadi tipe kolom akan ditebak. Pastikan akun punya SELECT pada tabel ini.',
            warnOnly: true,
        );

        $this->table(
            ['Kolom', 'Tipe Oracle', 'Tipe PostgreSQL', 'Catatan'],
            array_map(fn (array $c) => [
                $c['name'],
                $c['oracle_type'] ?? '—',
                $c['pg_type'],
                implode('; ', $c['warnings'] ?? []),
            ], $result['columns']),
        );

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn('     ! '.$warning);
        }
    }

    private function check(bool $ok, string $label, string $fix = '', bool $warnOnly = false): void
    {
        if ($ok) {
            $this->line("  <fg=green>✓</> {$label}");

            return;
        }

        if ($warnOnly) {
            $this->line("  <fg=yellow>!</> {$label}");
        } else {
            $this->failures++;
            $this->line("  <fg=red>✗</> {$label}");
        }

        if ($fix !== '') {
            $this->line('     → '.$fix);
        }
    }

    private function finish(): int
    {
        $this->newLine();

        if ($this->failures === 0) {
            $this->info('Semua pemeriksaan wajib lolos. Lanjutkan dengan membuat dataset dari halaman Dataset.');

            return self::SUCCESS;
        }

        $this->error("{$this->failures} pemeriksaan gagal. Perbaiki sesuai petunjuk → di atas, lalu jalankan lagi.");

        return self::FAILURE;
    }
}
