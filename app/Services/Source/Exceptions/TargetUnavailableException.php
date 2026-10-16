<?php

namespace App\Services\Source\Exceptions;

use Throwable;

/**
 * Koneksi ke PostgreSQL target (A atau B) gagal.
 */
class TargetUnavailableException extends SyncException
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(string $message, ?Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, 'target_unavailable', $context, $previous);
    }
}
