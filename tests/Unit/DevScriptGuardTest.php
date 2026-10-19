<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Skrip dev di root repo menyimpan password yang sudah diketahui.
 *
 * Kalau bocor ke server produksi, siapa pun yang bisa shell sudah punya akses
 * penuh ke panel dan ke semua token API. Jadi keduanya harus menolak jalan
 * ketika APP_ENV=production.
 */
class DevScriptGuardTest extends TestCase
{
    private function runScript(string $name): string
    {
        $path = base_path($name);

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_buat_akun_menolak_di_produksi(): void
    {
        $source = $this->runScript('scripts/buat-akun.php');

        $this->assertStringContainsString(
            "app()->environment('production')",
            $source,
            'buat-akun.php harus menolak jalan di produksi.',
        );

        // Penjaga harus ada SEBELUM kredensial dipakai, kalau tidak tidak berguna.
        $this->assertLessThan(
            strpos($source, "\$password ="),
            strpos($source, "environment('production')"),
            'Penjaga harus diperiksa sebelum password didefinisikan atau dipakai.',
        );
    }

    public function test_isi_data_contoh_menolak_di_produksi(): void
    {
        $source = $this->runScript('scripts/isi-data-contoh.php');

        $this->assertStringContainsString(
            "app()->environment('production')",
            $source,
            'isi-data-contoh.php harus menolak jalan di produksi.',
        );

        $this->assertLessThan(
            strpos($source, 'User::firstOrNew'),
            strpos($source, "environment('production')"),
            'Penjaga harus ada sebelum ada yang ditulis ke database.',
        );
    }

    public function test_keduanya_memakai_pola_penjaga_yang_sama(): void
    {
        foreach (['scripts/buat-akun.php', 'scripts/isi-data-contoh.php'] as $script) {
            $source = $this->runScript($script);

            $this->assertMatchesRegularExpression(
                "/if\s*\(\s*app\(\)->environment\('production'\)\s*\)\s*\{/",
                $source,
                "{$script} harus memakai pola if (app()->environment('production')) {",
            );

            $this->assertStringContainsString(
                'DITOLAK',
                $source,
                "{$script} harus mencetak DITOLAK supaya penolakan terlihat.",
            );
        }
    }
}