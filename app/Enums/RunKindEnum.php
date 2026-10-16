<?php

namespace App\Enums;

/**
 * Jenis pekerjaan yang dicatat di tabel `runs`.
 * Sync, copy A->B, dan rekonsiliasi full dipisahkan agar panel Riwayat
 * bisa menfilter tanpa menambah tabel baru.
 */
enum RunKindEnum: string
{
    case Sync = 'sync';
    case Copy = 'copy';
    case Reconcile = 'reconcile';
    case Test = 'test';
}
