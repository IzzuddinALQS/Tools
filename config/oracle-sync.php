<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default values
    |--------------------------------------------------------------------------
    | Nilai bawaan yang dipakai saat membuat Connection / Target / Dataset.
    */

    'defaults' => [
        // Driver yang dipilih di form pembuatan Connection.
        'driver' => env('SYNC_DEFAULT_DRIVER') ?: 'mock',

        'oracle_port' => 1521,
        'pg_port' => 5432,

        // Jadwal default dataset (cron 5 field, timezone aplikasi).
        // Default ARMORS: sync setiap jam, rekonsiliasi full tiap malam.
        'schedule_cron' => '0 * * * *',
        'reconcile_cron' => '0 2 * * *',

        // Batas keamanan per dataset (Keamanan 4).
        'timeout_seconds' => 300,
        'max_rows' => 1_000_000,
        'batch_size' => 2_000,

        // Batas baris untuk tombol "Preview" (tidak menyimpan apa pun).
        'preview_rows' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Oracle session settings
    |--------------------------------------------------------------------------
    | Dijalankan sekali per koneksi oleh OracleSession. Nilai-nilai ini adalah
    | allowlist LITERAL milik tool, BUKAH input user, dan tidak pernah melewati
    | SelectGuard. Dipakai supaya hasil baca konsisten antar eksekusi
    | (Aturan 4: tanpa konversi zona waktu / NLS yang tersembunyi).
    */
    'session' => [
        'time_zone' => '+00:00',
        'nls_date_format' => 'YYYY-MM-DD HH24:MI:SS',
        'nls_timestamp_format' => 'YYYY-MM-DD HH24:MI:SS.FF6',
        'nls_timestamp_tz_format' => 'YYYY-MM-DD HH24:MI:SS.FF6TZR',
        'nls_numeric_characters' => '.,',
    ],

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL session settings
    |--------------------------------------------------------------------------
    | Diterapkan oleh PgConnectionResolver agar hasil baca dari PG A / PG B
    | sebanding dengan sisi Oracle saat verifikasi hash.
    */
    'pg_session' => [
        'timezone' => 'UTC',
        'extra_float_digits' => 3,
        // Teks rapi & stabil untuk nilai bertipe teks panjang.
        'client_min_messages' => 'warning',
    ],

    /*
    |--------------------------------------------------------------------------
    | SelectGuard
    |--------------------------------------------------------------------------
    | Lapisan validasi kedua. Pengaman utama tetap user Oracle SELECT-only
    | (dibuat DBA). Daftar di bawah hanya untuk menangkap kesalahan pengguna.
    */
    'guard' => [
        // Batas panjang statement yang diterima.
        'max_sql_length' => 100_000,

        // Keyword yang membuat statement langsung ditolak (di luar literal string).
        'forbidden_keywords' => [
            'insert', 'update', 'delete', 'merge', 'upsert',
            'drop', 'create', 'alter', 'truncate', 'rename',
            'grant', 'revoke', 'commit', 'rollback', 'savepoint',
            'begin', 'declare', 'exception', 'execute', 'call',
            'lock', 'analyze', 'vacuum', 'comment', 'audit', 'flashback',
        ],

        // Pola berbahaya di luar literal string.
        'forbidden_patterns' => [
            'for_update' => '/\bfor\s+update\b/i',
            'db_link' => '/[a-z0-9_."]+\s*@\s*[a-z0-9_."]+/i',
            'execute_immediate' => '/\bexecute\s+immediate\b/i',
            'dbms_package' => '/\bdbms_[a-z0-9_]+\b/i',
            'utl_package' => '/\butl_[a-z0-9_]+\b/i',
            'dblink_package' => '/\bdblink\b/i',
            'remote' => '/\bremote\b/i',
            'pipe' => '/\bdbms_pipe\.(receive|send)\b/i',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lock
    |--------------------------------------------------------------------------
    | TTL lock default. Harus lebih besar dari timeout dataset supaya lock
    | tidak yatim bila worker mati mendadak.
    */
    'lock' => [
        // TTL lock default. Harus lebih besar dari timeout dataset supaya lock
        // tidak yatim bila worker mati mendadak.
        'ttl_seconds' => (int) env('SYNC_LOCK_TTL_SECONDS', 900),

        /*
         * Cache store yang dipakai HANYA untuk lock sinkronisasi.
         *
         *.Store ini wajib shared antar worker. Kalau tidak, dua worker bisa
         * menjalankan dataset yang sama bersamaan karena lock mereka tidak
         * sama-sama terlihat.
         *
         * Beda dari CACHE_STORE: yang ini hanya untuk lock, jadi cache umum
         * bisa tetap di database tanpa mengorbankan Sicherheit lock.
         */
        'store' => env('SYNC_LOCK_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Staging / swap
    |--------------------------------------------------------------------------
    | Prefix tabel staging. Swap terjadi dalam SATU transaksi PostgreSQL,
    | sehingga DDL (DROP + ALTER ... RENAME) bersifat atomik (Keamanan 5).
    */
    'staging' => [
        'suffix_prefix' => '__stg_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata schema untuk status sync (Aturan 6)
    |--------------------------------------------------------------------------
    | Tidak ada kolom teknis yang ditambahkan ke tabel data. Semua status
    | disimpan di schema terpisah supaya aplikasi bisa menampilkan
    | "data terakhir diperbarui pukul ...".
    */
    'meta_schema' => '_sync',

    /*
    |--------------------------------------------------------------------------
    | Mock driver
    |--------------------------------------------------------------------------
    | Fixture Oracle tiruan untuk pengembangan & test tanpa Oracle.
    */
    'fixtures' => [
        // `?:` dipakai karena env() mengembalikan string KOSONG bila variabel
        // ada tapi tidak diisi, bukan nilai default.
        'path' => env('SYNC_FIXTURE_PATH') ?: storage_path('app/fixtures'),
        'default_set' => 'armors',
    ],

];
