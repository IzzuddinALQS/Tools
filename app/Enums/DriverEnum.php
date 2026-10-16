<?php

namespace App\Enums;

/**
 * Driver koneksi sumber. `mock` hanya untuk pengembangan & test.
 */
enum DriverEnum: string
{
    case Mock = 'mock';
    case Oracle = 'oracle';

    public function label(): string
    {
        return match ($this) {
            self::Mock => 'Mock (Oracle tiruan)',
            self::Oracle => 'Oracle',
        };
    }
}
