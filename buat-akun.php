<?php

use App\Enums\UserRoleEnum;
use App\Models\User;

/*
 * Membuat akun admin untuk login panel.
 *
 * Jalankan:
 *     php artisan tinker
 *     require base_path('buat-akun.php');
 *
 * Ganti EMAIL dan PASSWORD di bawah sesuai keinginan.
 */

/*
 * Jaga: skrip ini membuat admin dengan password yang tertulis di berkas ini.
 * Kalau bocor ke server produksi, siapa pun yang bisa shell sudah punya akses
 * penuh ke panel dan ke semua token API. Jadi refuse to run di produksi.
 */
if (app()->environment('production')) {
    fwrite(STDERR, PHP_EOL);
    fwrite(STDERR, 'DITOLAK: skrip ini tidak boleh jalan di APP_ENV=production.'.PHP_EOL);
    fwrite(STDERR, 'Buat akun admin lewat migration atau query SQL langsung di server.'.PHP_EOL);
    fwrite(STDERR, 'Password di berkas ini sudah diketahui, jadi file ini tidak boleh bocor.'.PHP_EOL.PHP_EOL);

    return;
}

$email = 'admin@ods.local';
$password = 'ods12345';
$name = 'Admin Oracle Data Sync';

// Semua akun lama diturunkan jadi viewer supaya hanya akun ini yang bisa
// membuka halaman Driver.
User::query()->where('email', '!=', $email)->update(['role' => UserRoleEnum::Viewer->value]);

$user = User::firstOrNew(['email' => $email]);

$user->forceFill([
    'name' => $name,
    // Cast `hashed` di model, jadi isi plaintext apa adanya.
    'password' => $password,
    'role' => UserRoleEnum::Admin,
])->save();

echo '=== akun siap ===', PHP_EOL;
echo 'email    : ' . $user->email, PHP_EOL;
echo 'nama     : ' . $user->name, PHP_EOL;
echo 'role     : ' . $user->role->value, PHP_EOL;
echo 'password : ' . $password, PHP_EOL, PHP_EOL;
echo 'seluruh akun:', PHP_EOL;

foreach (User::all() as $row) {
    echo '  - ', str_pad($row->email, 28), $row->role->value, PHP_EOL;
}
