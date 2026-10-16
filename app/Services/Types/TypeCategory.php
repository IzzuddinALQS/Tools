<?php

namespace App\Services\Types;

/**
 * Kategori logis dari tipe PostgreSQL hasil pemetaan.
 *
 * Digesti konten (hash verifikasi) selalu berdasarkan kategori ini, bukan
 * berdasarkan string tipe, supaya sisi Oracle dan sisi PostgreSQL memakai
 * aturan kanonikalisasi yang persis sama.
 */
enum TypeCategory: string
{
    case Numeric = 'numeric';
    case Float = 'float';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Bytea = 'bytea';
    case Timestamp = 'timestamp';
    case TimestampTz = 'timestamptz';
    case Interval = 'interval';
    case Text = 'text';
}
