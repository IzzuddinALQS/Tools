<?php

/*
|--------------------------------------------------------------------------
| Router untuk server bawaan PHP
|--------------------------------------------------------------------------
| Laravel 11+ tidak lagi menyertakan `server.php` di root proyek, padahal
| `composer serve` membutuhkannya. File ini menggantikannya.
|
| Sengaja TIDAK memakai helper Laravel (mis. `public_path()`) supaya file ini
| bisa dimuat sebelum aplikasi di-boot.
|
| Perintah:
|     php -S 127.0.0.1:8000 -t public server.php
|
| Kenapa tidak `php artisan serve` di Windows? Lihat README bagian
| "Menjalankan Lokal dengan Mode MOCK". Ringkasnya: Laravel meneruskan
| environment ke child process dengan daftar putih huruf besar (`PATH`,
| `SYSTEMROOT`), sedangkan `$_ENV` Windows menuliskan `Path` dan `SystemRoot`.
| Pencocokan `in_array()` case-sensitive, jadi keduanya justru dihapus dan
| Winsock gagal inisialisasi sehingga `php -S` gagal bind port.
*/

$publicPath = __DIR__ . '/public';

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

// Berkas statis (CSS/JS hasil `npm run build`) dilayani apa adanya.
if ($uri !== '/' && ! is_dir($publicPath . $uri) && file_exists($publicPath . $uri)) {
    return false;
}

require_once $publicPath . '/index.php';
