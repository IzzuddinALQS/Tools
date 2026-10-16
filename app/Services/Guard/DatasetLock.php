<?php

namespace App\Services\Guard;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Satu dataset tidak boleh berjalan ganda (Keamanan 4).
 *
 * Lock diambil dari cache store yang SHARED antar worker. Di produksi
 * multi-host, pastikan `SYNC_LOCK_STORE` memakai Redis.
 */
class DatasetLock
{
    public function __construct(
        private readonly int $ttlSeconds = 900,
    ) {
    }

    /**
     * Jalankan $callback sambil memegang lock.
     *
     * Mengembalikan null bila lock sedang dipakai proses lain.
     */
    public function run(string $key, callable $callback): mixed
    {
        $lock = $this->acquire($key);

        // Penting: acquire() hanya MEMBUAT objek Lock. Yang benar-benar
        // mengunci adalah get(). Tanpa ini, lock tidak pernah dipakai dan
        // dataset bisa berjalan ganda.
        if ($lock === null || ! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->release($lock);
        }
    }

    public function acquire(string $key): ?Lock
    {
        try {
            return Cache::lock('sync:'.$key, $this->ttlSeconds);
        } catch (Throwable) {
            // Tanpa cache yang bisa dipakai, lebih aman proceed tanpa lock
            // daripada memblokir seluruh sinkronisasi.
            return null;
        }
    }

    public function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // Lock sudah kedaluwarsa atau store tidak bisa dijangkau.
        }
    }

    public function isLocked(string $key): bool
    {
        try {
            return Cache::lock('sync:'.$key, $this->ttlSeconds)->get();
        } catch (Throwable) {
            return false;
        }
    }

    public function datasetKey(string $datasetUid): string
    {
        return 'dataset:'.$datasetUid;
    }

    public function copyKey(string $datasetUid, string $targetUid): string
    {
        return 'copy:'.$datasetUid.':'.$targetUid;
    }
}
