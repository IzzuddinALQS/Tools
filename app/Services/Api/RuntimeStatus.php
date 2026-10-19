<?php

namespace App\Services\Api;

use App\Enums\RunStatusEnum;
use App\Models\Dataset;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Keadaan runtime yang perlu diketahui pemanggil API.
 *
 * Dua hal yang dulu tidak terlihat dari sisi pemanggil, sehingga `202` bisa
 * berbohong:
 *
 * 1. Apakah sinkronisasi dataset itu sedang berjalan. Dulu endpoint selalu
 *    membalas 202, jadi aplikasi tidak bisa membedakan "baru dijalankan"
 *    dari "sudah ada yang jalan".
 * 2. Apakah ada worker yang hidup. Tanpa worker, job menggantung selamanya
 *    sementara pemanggil sudah diberi tahu "dijadwalkan".
 */
final class RuntimeStatus
{
    /**
     * Kunci di tabel `settings`.
     *
     * Disimpan di database, bukan cache, supaya konsisten antar worker dan
     * bertahan setelah restart — sama seperti kill switch.
     */
    public const WORKER_HEARTBEAT = 'queue_worker_heartbeat';

    /**
     * Berapa lama heartbeat dianggap basi, dalam detik.
     *
     * Harus jauh lebih besar dari selang heartbeat, tapi jauh lebih kecil
     * daripada waktu yang dibutuhkan orang untuk menulis ulang bottom.
     */
    public const HEARTBEAT_STALE_AFTER = 300;

    /**
     * Apakah dataset ini sedang punya eksekusi yang hidup?
     *
     * Sumbernya tabel `runs`, bukan cache lock. Cache lock tidak bisa diperiksa
     * tanpa mengambilnya — lihat catatan di App\Services\Guard\DatasetLock.
     *
     * Ada batas waktu supaya run yang yatim — worker mati mendadak sehingga
     * `finished_at` tidak pernah terisi — tidak membuat dataset terlihat
     * "sedang berjalan" selamanya.
     */
    public static function isDatasetRunning(Dataset $dataset): bool
    {
        $window = max(60, (int) $dataset->timeout_seconds) + 300;

        return $dataset->runs()
            ->where('status', RunStatusEnum::Running)
            ->where('started_at', '>=', now()->subSeconds($window))
            ->exists();
    }

    /**
     * Status worker antrean.
     *
     * @return array{known:bool,alive:bool,at:?string,age:?int}
     */
    public static function workerStatus(): array
    {
        $beat = Setting::get(self::WORKER_HEARTBEAT);

        $at = is_array($beat) ? ($beat['at'] ?? null) : null;

        // Belum pernah ada heartbeat sama sekali berarti "tidak diketahui",
        // bukan "mati". Instalasi yang tidak mengaktifkan scheduler tidak
        // boleh ikut ditolak dengan 503.
        if (! is_string($at) || $at === '') {
            return ['known' => false, 'alive' => true, 'at' => null, 'age' => null];
        }

        try {
            /*
             * Urutannya penting: diffInSeconds() mengembalikan nilai mutlak,
             * jadi kalau now() yang lebih dulu, hasilnya tetap positif dan
             * heartbeat lama akan terlihat "segar".
             */
            $age = (int) Carbon::parse($at)->diffInSeconds(now());
        } catch (\Throwable) {
            return ['known' => false, 'alive' => true, 'at' => $at, 'age' => null];
        }

        return [
            'known' => true,
            'alive' => $age <= self::HEARTBEAT_STALE_AFTER,
            'at' => $at,
            'age' => $age,
        ];
    }

    public static function recordHeartbeat(?string $queue = null): void
    {
        Setting::put(self::WORKER_HEARTBEAT, [
            'at' => now()->toIso8601String(),
            'pid' => getmypid() ?: null,
            'queue' => $queue,
        ]);
    }
}