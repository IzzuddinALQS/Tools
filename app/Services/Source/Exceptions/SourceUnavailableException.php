<?php

namespace App\Services\Source\Exceptions;

use Throwable;

/**
 * Sumber data (Oracle / mock) tidak bisa dihubungi.
 *
 * Tool tidak boleh crash: exception ini ditangkap SyncEngine, run ditandai
 * `failed` dengan pesan rapi, dan data lama di PG A tetap utuh.
 */
class SourceUnavailableException extends SyncException
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(string $message, ?Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, 'source_unavailable', $context, $previous);
    }
}
