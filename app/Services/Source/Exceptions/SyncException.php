<?php

namespace App\Services\Source\Exceptions;

use RuntimeException;

/**
 * Exception yang aman ditampilkan ke user: pesan rapi, tanpa kredensial
 * (Keamanan 6). Semua pesan yang bisa mengandung detail koneksi harus
 * melewati ErrorMessageSanitizer lebih dulu.
 */
class SyncException extends RuntimeException
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(
        string $message,
        protected string $errorCode = 'sync_error',
        protected array $context = [],
        ?\Throwable $previous = null,
    ) {
        // Previous HANYA boleh lewat constructor; Exception tidak punya
        // setPrevious().
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->context;
    }
}
