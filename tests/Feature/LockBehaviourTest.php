<?php

namespace Tests\Feature;

use App\Services\Guard\DatasetLock;
use App\Services\Guard\LockUnavailable;
use App\Services\Sync\SyncRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Support\InteractsWithDatabases;

/**
 * Uji lapis lock sinkronisasi.
 *
 * Dua kondisi yang sebelumnya tercampur jadi satu dan menghasilkan pesan
 * menyesatkan:
 *
 * - lock dipegang proses lain  -> skip, dataset memang sedang jalan
 * - cache store tidak usable  -> tetap jalan, hanya dicatat peringatan
 */
class LockBehaviourTest extends SyncTestCase
{
    use InteractsWithDatabases;

    /**
     * Simulasikan cache store yang mati total, dengan cara mendaftarkan store
     * palsu yang selalu melempar saat lock diambil.
     */
    private function breakCacheStore(string $name): void
    {
        Cache::extend($name, fn () => new class implements \Illuminate\Contracts\Cache\Store, \Illuminate\Contracts\Cache\LockProvider
        {
            public function get($key) {}

            public function many(array $keys) {}

            public function put($key, $value, $seconds) {}

            public function putMany(array $values, $seconds) {}

            public function increment($key, $value = 1) {}

            public function decrement($key, $value = 1) {}

            public function forever($key, $value) {}

            public function forget($key) {}

            public function flush() {}

            public function getPrefix() {}

            public function lock($name, $seconds = 0, $owner = null)
            {
                throw new \RuntimeException('store mati');
            }

            public function restoreLock($name, $owner) {}
        });
    }

    public function test_lock_memakai_store_yang_dikonfigurasi(): void
    {
        /*
         * SYNC_LOCK_STORE harus benar-benar dipakai. Sebelumnya nilai ini
         * didefinisikan di config tapi tidak pernah dibaca, jadi arahan README
         * "pakai redis untuk multi-host" tidak berefek apa pun.
         */
        config(['oracle-sync.lock.store' => 'array']);

        $lock = app(DatasetLock::class);
        $this->assertSame('array', $lock->store());

        $handle = $lock->acquire('dataset:test');

        // Kalau lock benar-benar jatuh ke store "array", ambilnya dari sana
        // harus berhasil tanpa menyentuh database.
        $this->assertTrue(
            Cache::store('array')->lock('sync:dataset:test', 900)->get(),
            'Lock harus dibuat di store yang dikonfigurasi, bukan di store bawaan.',
        );

        $lock->release($handle);
    }

    public function test_ttl_dibaca_dari_config(): void
    {
        config([
            'oracle-sync.lock.store' => 'array',
            'oracle-sync.lock.ttl_seconds' => 42,
        ]);

        $lock = app(DatasetLock::class);
        $handle = $lock->acquire('dataset:ttl');

        // $seconds diprotected di kelas lock, jadi dibaca lewat refleksi.
        $property = new \ReflectionProperty($handle, 'seconds');
        $property->setAccessible(true);

        $this->assertSame(42, $property->getValue($handle));

        $lock->release($handle);
    }

    public function test_cache_mati_tetap_menjalankan_sinkronisasi(): void
    {
        $this->breakCacheStore('broken');
        config(['oracle-sync.lock.store' => 'broken']);

        Log::spy();

        $dataset = $this->dataset();
        $run = app(SyncRunner::class)->run($dataset);

        /*
         * Sebelumnya kondisi ini menghasilkan status `skipped` dengan pesan
         * "sedang berjalan di proses lain" — padahal tidak ada yang jalan.
         * Itu membuat operator mencari dataset yang tidak ada.
         */
        $this->assertSame(
            'success',
            $run->status->value,
            'Cache mati tidak boleh membuat sinkronisasi dilaporkan sebagai skipped.',
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Lock sinkronisasi tidak bisa diambil'));
    }

    public function test_lock_yang_dipegang_proses_lain_tetap_menghasilkan_skipped(): void
    {
        config(['oracle-sync.lock.store' => 'array']);

        $dataset = $this->dataset();

        $lock = app(DatasetLock::class);
        $held = $lock->acquire($lock->datasetKey($dataset->uid));
        $this->assertTrue($held->get(), 'Lock harus bisa diambil sebelum test.');

        $run = app(SyncRunner::class)->run($dataset);

        $this->assertSame('skipped', $run->status->value);
        $this->assertSame('already_running', $run->error_code);
        $this->assertStringContainsString('sedang berjalan', (string) $run->error_message);

        $lock->release($held);
    }

    public function test_acquire_melempar_lock_unavailable_bukan_null(): void
    {
        $this->breakCacheStore('broken2');
        config(['oracle-sync.lock.store' => 'broken2']);

        $this->expectException(LockUnavailable::class);

        app(DatasetLock::class)->acquire('dataset:x');
    }

    public function test_store_tidak_ada_ditolak_jelas(): void
    {
        config(['oracle-sync.lock.store' => 'store-yang-tidak-ada']);

        $this->expectException(LockUnavailable::class);

        app(DatasetLock::class)->acquire('dataset:y');
    }
}