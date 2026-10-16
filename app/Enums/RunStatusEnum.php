<?php

namespace App\Enums;

/**
 * Status eksekusi. Semua nilai ini sudah tersedia saat Stage 2 (trigger `api`).
 */
enum RunStatusEnum: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';

    /** Lock sudah dipakai proses lain — tidak dieksekusi. */
    case Skipped = 'skipped';

    /** Kill switch aktif — tidak ada penulisan sama sekali. */
    case Paused = 'paused';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Skipped, self::Paused], true);
    }
}
