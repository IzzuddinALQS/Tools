<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Connection;
use App\Models\Dataset;
use App\Models\Target;
use App\Models\User;
use Tests\Feature\SyncTestCase;
use Tests\Support\InteractsWithDatabases;

/**
 * Bukti perilaku (U5), bukan hanya cocok string.
 *
 * Skrip `buat-akun.php` dan `isi-data-contoh.php` menyimpan password yang
 * sudah diketahui. Kalau bocor ke server produksi, siapa pun yang bisa shell
 * sudah punya akses penuh ke panel dan ke semua token API.
 */
class DevScriptGuardTest extends SyncTestCase
{
    use InteractsWithDatabases;

    /**
     * Jalankan skrip dalam environment produksi dan kembalikan nilai
     * balikannya.
     *
     * Pesan penolakan ditulis ke STDERR, bukan ke output buffer, jadi yang
     * diperiksa di sini efeknya (tidak ada yang ditulis), bukan pesannya.
     * Isi pesannya dijaga test statis di Tests\Unit\DevScriptGuardTest.
     */
    private function runScriptInProduction(string $name): mixed
    {
        $previous = app()->environment();

        app()->detectEnvironment(fn () => 'production');

        ob_start();

        try {
            return require base_path($name);
        } finally {
            ob_end_clean();

            app()->detectEnvironment(fn () => $previous);
        }
    }

    public function test_buat_akun_menolak_dan_tidak_membuat_apa_pun(): void
    {
        $result = $this->runScriptInProduction('buat-akun.php');

        $this->assertNull($result, 'Skrip harus berhenti tanpa melanjutkan.');
        $this->assertSame(0, User::count(), 'Tidak boleh ada akun yang dibuat.');
    }

    public function test_isi_data_contoh_menolak_dan_tidak_menulis_apa_pun(): void
    {
        $result = $this->runScriptInProduction('isi-data-contoh.php');

        $this->assertNull($result, 'Skrip harus berhenti tanpa melanjutkan.');

        $this->assertSame(0, Connection::count(), 'Tidak boleh ada koneksi yang dibuat.');
        $this->assertSame(0, Target::count(), 'Tidak boleh ada target yang dibuat.');
        $this->assertSame(0, Dataset::count(), 'Tidak boleh ada dataset yang dibuat.');
        $this->assertSame(0, ApiToken::count(), 'Tidak boleh ada token yang diterbitkan.');
    }

    public function test_buat_akun_tetap_berjalan_di_lokal(): void
    {
        /*
         * Penjaga tidak boleh memblokir pengembangan. Kalau ini gagal, berarti
         * lingkungan lokal ikut terpengaruh.
         */
        $this->assertSame('testing', app()->environment());

        ob_start();

        try {
            require base_path('buat-akun.php');
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertStringNotContainsString('DITOLAK', $output);
        $this->assertSame(1, User::count());
        $this->assertSame('admin@ods.local', User::firstOrFail()->email);
    }
}