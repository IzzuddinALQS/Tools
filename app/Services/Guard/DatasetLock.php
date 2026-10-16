<?php

namespace App\Services\Guard;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Satu dataset tidak boleh berjalan ganda (Keamanan 4).
 *
 * Lock diambil dari cache store yang SHARED antar worker, ditentukan lewat
 * `SYNC_LOCK_STORE` — BUKAN cache store umum. Di produksi multi-host, keduanya
 * wajib Redis.
 */
class DatasetLock
{
    private readonly string $store;

    /**
     * @param  int  $ttlSeconds  ignored; TTL diambil dari `SYNC_LOCK_TTL_SECONDS`
     *                          lewat config. Argumen ini disimpan supaya
     *                          pemanggil lama tidak ikut rusak.
     */
    public function __construct(
        private readonly int $ttlSeconds = 900,
        ?string $store = null,
    ) {
        $this->store = $store ?? (string) config('oracle-sync.lock.store', 'database');
    }

    /**
     * Cache store yang benar-benar dipakai untuk lock.
     */
    public function store(): string
    {
        return $this->store;
    }

    /**
     * Jalankan $callback sambil memegang lock.
     *
     * Mengembalikan null HANYA bila lock sedang dipegang proses lain. Itu
     * satu-satunya kondisi yang berarti "dataset sudah jalan".
     *
     * Bila cache store tidak bisa dipakai, callback tetap dijalankan — lebih
     * baik berisiko jalan ganda satu kali daripada seluruh sinkronisasi
     * berhenti diam-diam. Kasus itu dicatat sebagai peringatan di log.
     */
    public function run(string $key, callable $callback): mixed
    {
        try {
            $lock = $this->acquire($key);
        } catch (LockUnavailable $exception) {
            Log::warning('Lock sinkronisasi tidak bisa diambil; lanjut tanpa lock.', [
                'key' => $key,
                'store' => $this->store,
                'reason' => $exception->getMessage(),
            ]);

            return $callback();
        }

        // Penting: acquire() hanya MEMBUAT objek Lock. Yang benar-benar
        // mengunci adalah get(). Tanpa ini, lock tidak pernah dipakai dan
        // dataset bisa berjalan ganda.
        if (! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->release($lock);
        }
    }

    /**
     * @throws LockUnavailable  saat cache store tidak bisa dipakai
     */
    public function acquire(string $key): Lock
    {
        $store = $this->store;

        try {
            $resolved = Cache::store($store);

            if (! $resolved->getStore() instanceof LockProvider) {
                throw new LockUnavailable('store "'.$store.'" tidak mendukung lock');
            }

            return $resolved->lock('sync:'.$key, $this->ttl());
        } catch (LockUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new LockUnavailable($exception->getMessage(), 0, $exception);
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

    public function datasetKey(string $datasetUid): string
    {
        return 'dataset:'.$datasetUid;
    }

    public function copyKey(string $datasetUid, string $targetUid): string
    {
        return 'copy:'.$datasetUid.':'.$targetUid;
    }

    private function ttl(): int
    {
        // Config dibaca per pemanggilan, bukan di constructor, supaya
        // perubahan di test langsung berlaku tanpa membuat ulang objek.
        return (int) config('oracle-sync.lock.ttl_seconds', $this->ttlSeconds);
    }
}