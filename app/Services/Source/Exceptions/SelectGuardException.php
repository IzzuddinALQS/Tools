<?php

namespace App\Services\Source\Exceptions;

use Throwable;

/**
 * SQL ditolak oleh SelectGuard.
 *
 * Ini adalah lapisan validasi KEDUA. Pengaman utama tetap user Oracle
 * yang SELECT-only (dibuat DBA) — lihat README.
 */
class SelectGuardException extends SyncException
{
    /**
     * @param  array<int,string>  $reasons
     */
    public function __construct(string $message, array $reasons = [])
    {
        parent::__construct(
            $message,
            'sql_not_allowed',
            ['reasons' => $reasons],
        );
    }

    /**
     * @return array<int,string>
     */
    public function reasons(): array
    {
        return $this->context()['reasons'] ?? [];
    }
}
