<?php

namespace App\Services\Sync;

/**
 * Hasil satu eksekusi sinkronisasi.
 */
final class SyncResult
{
    /**
     * @param  array<string,mixed>|null  $verification
     * @param  array<int,string>  $warnings
     */
    public function __construct(
        public readonly int $rowCount,
        public readonly bool $verificationMatched,
        public readonly ?array $verification = null,
        public readonly array $warnings = [],
        public readonly ?string $watermark = null,
        public readonly string $executedSql = '',
    ) {
    }
}
