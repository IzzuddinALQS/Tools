<?php

namespace App\Enums;

/**
 * Mode sinkronisasi dataset.
 *
 * full        Selalu menyalin ulang seluruh hasil query. HANYA mode ini yang
 *             menjamin hasil identik, termasuk baris yang dihapus di Oracle.
 * incremental Hanya mengambil baris yang berubah sejak sync terakhir lalu
 *             upsert. Baris yang DIHAPUS di Oracle tidak terdeteksi, jadi
 *             wajib disertai jadwal rekonsiliasi `full` berkala.
 */
enum SyncModeEnum: string
{
    case Full = 'full';
    case Incremental = 'incremental';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::Incremental => 'Incremental',
        };
    }
}
