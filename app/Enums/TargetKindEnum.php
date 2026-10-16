<?php

namespace App\Enums;

/**
 * Peran target PostgreSQL.
 *
 * pg_a = PostgreSQL A, kanonik. Hanya boleh ada SATU (dijamin partial
 *        unique index pada tabel `targets`).
 * pg_b = PostgreSQL B, salinan untuk satu aplikasi. Bisa banyak.
 */
enum TargetKindEnum: string
{
    case PgA = 'pg_a';
    case PgB = 'pg_b';

    public function label(): string
    {
        return match ($this) {
            self::PgA => 'PostgreSQL A (kanonik)',
            self::PgB => 'PostgreSQL B (salinan aplikasi)',
        };
    }
}
