<?php

namespace App\Services\Source;

use App\Enums\DriverEnum;
use App\Models\Connection as ConnectionModel;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Memilih reader berdasarkan driver koneksi.
 *
 * `mock` dan `oracle` mengimplementasikan interface yang sama, jadi seluruh
 * alur berjalan lewat kode yang identik. Ini yang membuat mode mock benar-benar
 * berguna: bukan simulasi terpisah, tapi jalur eksekusi yang sama.
 */
class SourceReaderFactory
{
    /** @var array<string,SourceReader> */
    private array $cache = [];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function for(ConnectionModel $connection): SourceReader
    {
        $key = $connection->uid.':'.($connection->updated_at?->timestamp ?? '0');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $reader = match ($connection->driver) {
            DriverEnum::Mock => $this->container->make(MockOracleReader::class, [
                'connection' => $connection,
            ]),
            DriverEnum::Oracle => $this->container->make(OracleReader::class, [
                'connection' => $connection,
            ]),
            default => throw new InvalidArgumentException(
                'Driver tidak dikenal: '.(is_string($connection->driver) ? $connection->driver : '')
            ),
        };

        return $this->cache[$key] = $reader;
    }

    public function forget(ConnectionModel $connection): void
    {
        $this->cache = [];
    }
}
