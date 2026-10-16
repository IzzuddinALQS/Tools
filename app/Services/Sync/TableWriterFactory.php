<?php

namespace App\Services\Sync;

/**
 * TableWriter dibuat per dataset karena ukuran batch berbeda-beda
 * (Keamanan 4: batch streaming, bukan muat sekaligus).
 */
class TableWriterFactory
{
    public function make(int $batchSize = 2000): TableWriter
    {
        return new TableWriter(max(1, $batchSize));
    }
}
