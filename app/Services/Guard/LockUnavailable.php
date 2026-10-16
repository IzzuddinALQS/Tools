<?php

namespace App\Services\Guard;

use RuntimeException;

/**
 * Cache store tidak bisa dipakai untuk lock.
 *
 * Sengaja dipisahkan dari kondisi "lock sedang dipegang proses lain". Dua
 * kondisi itu kelihatan mirip tapi butuh tindakan yang berlawanan:
 *
 * - sudah dipegang proses lain -> jangan jalankan, kembalikan null
 * - tidak bisa mengunci         -> tetap jalankan, agar satu outage cache
 *                                 tidak menghentikan seluruh sinkronisasi
 */
class LockUnavailable extends RuntimeException
{
}