<?php

namespace App\Services\Guard;

use App\Models\Setting;

/**
 * Kill switch global: tombol "Pause semua job" (Keamanan 7).
 *
 * Disimpan di tabel `settings`, BUKAN di cache, supaya konsisten antar
 * beberapa worker dan queue server serta bertahan setelah restart.
 */
class KillSwitch
{
    /**
     * @return array{paused:bool, at:?string, by:?int, reason:?string}
     */
    public function state(): array
    {
        return Setting::pauseState();
    }

    public function isPaused(): bool
    {
        return (bool) ($this->state()['paused'] ?? false);
    }

    public function pause(?int $userId = null, ?string $reason = null): void
    {
        Setting::put(Setting::GLOBAL_PAUSE, [
            'paused' => true,
            'at' => now()->toIso8601String(),
            'by' => $userId,
            'reason' => $reason,
        ], $userId);
    }

    public function resume(?int $userId = null): void
    {
        Setting::put(Setting::GLOBAL_PAUSE, [
            'paused' => false,
            'at' => null,
            'by' => null,
            'reason' => null,
        ], $userId);
    }

    /**
     * @throws \App\Services\Source\Exceptions\SyncException
     */
    public function assertRunning(): void
    {
        if ($this->isPaused()) {
            $state = $this->state();

            throw new \App\Services\Source\Exceptions\SyncException(
                'Semua job sedang di-pause (kill switch aktif). Tidak ada penulisan ke PostgreSQL A atau B.',
                'global_paused',
                ['since' => $state['at'] ?? null],
            );
        }
    }
}
