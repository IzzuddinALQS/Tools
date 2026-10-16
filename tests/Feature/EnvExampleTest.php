<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Jaga agar `.env.example` tidak kembali jadi bawaan Laravel.
 *
 * D1 di PR.md terjadi karena tidak ada yang protes saat berkas itu
 * kehilangan seluruh konfigurasi tool ini. Test inilah penjaganya: begitu
 * ada variabel baru yang dibutuhkan, test ini gagal sampai `.env.example`
 * ikut diperbarui.
 */
class EnvExampleTest extends TestCase
{
    private function contents(): string
    {
        $path = base_path('.env.example');

        $this->assertFileExists($path, '.env.example harus ada di root repo.');

        return (string) file_get_contents($path);
    }

    /**
     * @return array<int,string>
     */
    private function definedKeys(): array
    {
        preg_match_all('/^([A-Z0-9_]+)=/m', $this->contents(), $matches);

        return $matches[1];
    }

    public function test_bukan_berkas_bawaan_laravel(): void
    {
        $contents = $this->contents();

        $this->assertStringNotContainsString(
            'APP_NAME=Laravel',
            $contents,
            '.env.example masih bawaan Laravel. Isi ulang dengan konfigurasi tool ini.',
        );

        $this->assertStringNotContainsString(
            'DB_CONNECTION=sqlite',
            $contents,
            'Tool ini butuh PostgreSQL. SQLite tidak bisa dipakai.',
        );
    }

    public function test_memuat_semua_kunci_yang_dibutuhkan(): void
    {
        $required = [
            // Aplikasi
            'APP_KEY',
            'APP_ENV',

            // Database metadata
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',

            // Sync
            'SYNC_DEFAULT_DRIVER',

            // Lock — nilai ini dulu konfigurasi mati (B1)
            'SYNC_LOCK_STORE',
            'SYNC_LOCK_TTL_SECONDS',

            // Session
            'SESSION_DRIVER',
            'SESSION_ENCRYPT',

            // Antrean & cache
            'QUEUE_CONNECTION',
            'CACHE_STORE',

            // Proxy (B4)
            'TRUSTED_PROXIES',
        ];

        $defined = $this->definedKeys();

        foreach ($required as $key) {
            $this->assertContains(
                $key,
                $defined,
                "Variabel {$key} tidak ada di .env.example. Tanpa itu, instalasi baru salah setup.",
            );
        }
    }

    public function test_app_key_kosong_agar_harus_dijalankan_key_generate(): void
    {
        $this->assertMatchesRegularExpression(
            '/^APP_KEY=\s*$/m',
            $this->contents(),
            'APP_KEY harus kosong di .env.example supaya tidak ikut menyalin kunci laptop lain.',
        );
    }

    public function test_tidak_membocorkan_kunci_enkripsi(): void
    {
        /*
         * Password PostgreSQL lokal di contoh ini bukan rahasia, tapi APP_KEY
         * berbeda sifatnya: menyalinnya berarti menyalin session yang
         * bisa dibuka, termasuk token API yang pernah lewat sana.
         */
        $this->assertDoesNotMatchRegularExpression(
            '/^APP_KEY=.+$/m',
            $this->contents(),
            'APP_KEY harus kosong di .env.example supaya tidak ikut menyalin kunci laptop lain.',
        );
    }

    public function test_tidak_menyimpan_nilai_rahasia_lain(): void
    {
        $contents = $this->contents();

        // Pola umum kalau ada token atau password yang ikut ter-commit.
        $this->assertDoesNotMatchRegularExpression(
            '/^(ODS_API_TOKEN|DB_PASSWORD_PROD|ORACLE_PASSWORD)=.+$/m',
            $contents,
            'Jangan taruh kredensial produksi di .env.example.',
        );
    }

    public function test_nilai_port_konsisten_dengan_phpunit(): void
    {
        /*
         * Jebakan yang pernah terjadi: .env memakai satu port, phpunit.xml
         * memakai port lain, lalu test gagal dengan error koneksi yang tidak
         * menjelaskan asal-usulnya.
         */
        $xml = (string) file_get_contents(base_path('phpunit.xml'));

        preg_match('/<env name="DB_PORT" value="([^"]+)"/', $xml, $xmlMatch);
        preg_match('/^DB_PORT=(.*)$/m', $this->contents(), $envMatch);

        $this->assertNotEmpty($xmlMatch, 'phpunit.xml harus punya DB_PORT.');
        $this->assertNotEmpty($envMatch, '.env.example harus punya DB_PORT.');

        $this->assertSame(
            trim($envMatch[1]),
            $xmlMatch[1],
            'Port di .env.example harus sama dengan phpunit.xml, kalau tidak test tidak akan konek.',
        );
    }
}
