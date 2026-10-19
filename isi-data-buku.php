<?php

/*
 * Setup Oracle Data Sync untuk uji coba CRUD buku.
 *
 * Isi skrip ini HANYA konfigurasi: akun, koneksi sumber, target, dan dataset.
 * Data buku sendiri TIDAK ada di sini. Data itu ada di
 * storage/app/fixtures/buku.json dan dibaca oleh driver mock.
 *
 * Jalankan:
 *     php artisan tinker
 *     require base_path('isi-data-buku.php');
 *
 * atau:
 *     php artisan tinker --execute="require base_path('isi-data-buku.php');"
 *
 * Aman dijalankan berulang: semuanya update-or-create.
 */

if (app()->environment('production')) {
    fwrite(STDERR, PHP_EOL);
    fwrite(STDERR, 'DITOLAK: skrip ini tidak boleh jalan di APP_ENV=production.'.PHP_EOL);
    fwrite(STDERR, 'Skrip ini menimpa koneksi, target, dan dataset.'.PHP_EOL.PHP_EOL);

    return;
}

/* ------------------------------------------------------------------ */
/* 1. Akun admin                                                       */
/* ------------------------------------------------------------------ */

$admin = App\Models\User::firstOrNew(['email' => 'admin@ods.local']);

$admin->forceFill([
    'name' => 'Admin Oracle Data Sync',
    'password' => 'ods12345',
    'role' => App\Enums\UserRoleEnum::Admin,
])->save();

echo '=== akun siap ==='.PHP_EOL;
echo 'email    : '.$admin->email.PHP_EOL;
echo 'password : ods12345'.PHP_EOL;
echo 'role     : admin'.PHP_EOL.PHP_EOL;

/* ------------------------------------------------------------------ */
/* 2. Koneksi sumber (driver mock, tanpa Oracle)                       */
/* ------------------------------------------------------------------ */

$mockArmors = App\Models\Connection::updateOrCreate(
    ['name' => 'mock-armors'],
    [
        'description' => 'Fixture bawaan untuk demonstrasi ARMORS.',
        'driver' => App\Enums\DriverEnum::Mock,
        'fixture_set' => 'armors',
        'username' => 'mock_reader',
        'password' => 'mock-secret',
        'is_active' => true,
    ]
);

$mockBuku = App\Models\Connection::updateOrCreate(
    ['name' => 'mock-buku'],
    [
        'description' => 'Fixture lokal untuk uji coba CRUD buku.',
        'driver' => App\Enums\DriverEnum::Mock,
        'fixture_set' => 'buku',
        'username' => 'mock_reader',
        'password' => 'mock-secret',
        'is_active' => true,
    ]
);

echo '=== koneksi sumber siap ==='.PHP_EOL;
echo '  mock-armors -> fixture: armors'.PHP_EOL;
echo '  mock-buku   -> fixture: buku'.PHP_EOL.PHP_EOL;

/* ------------------------------------------------------------------ */
/* 3. Target PostgreSQL                                                 */
/* ------------------------------------------------------------------ */

// PG A: mirror kanonik, hanya boleh ada satu. Dijamin unique index
// targets_single_pg_a, jadi pemakaian kedua akan ditolak database.
$pgA = App\Models\Target::updateOrCreate(
    ['kind' => App\Enums\TargetKindEnum::PgA],
    [
        'name' => 'PG A (sinkronisasi)',
        'description' => 'Mirror kanonik. Hanya boleh ada satu.',
        'host' => '127.0.0.1',
        'port' => 5433,
        'database' => 'ods_pg_a',
        'username' => 'postgres',
        'password' => 'postgres',
        'sslmode' => 'prefer',
        'is_active' => true,
    ]
);

// PG B: satu salinan per aplikasi. app_name wajib diisi, dijamin constraint
// targets_pg_b_needs_app.
$pgBarmors = App\Models\Target::updateOrCreate(
    ['kind' => App\Enums\TargetKindEnum::PgB, 'app_name' => 'armors'],
    [
        'name' => 'PG B armors',
        'description' => 'Salinan read-only untuk aplikasi ARMORS.',
        'host' => '127.0.0.1',
        'port' => 5433,
        'database' => 'ods_pg_b_armors',
        'username' => 'postgres',
        'password' => 'postgres',
        'readonly_username' => 'ods_ro',
        'sslmode' => 'prefer',
        'is_active' => true,
    ]
);

$pgBBuku = App\Models\Target::updateOrCreate(
    ['kind' => App\Enums\TargetKindEnum::PgB, 'app_name' => 'buku'],
    [
        'name' => 'PG B buku',
        'description' => 'Salinan read-only untuk aplikasi CRUD buku.',
        'host' => '127.0.0.1',
        'port' => 5433,
        'database' => 'ods_pg_b_buku',
        'username' => 'postgres',
        'password' => 'postgres',
        'readonly_username' => 'buku_ro',
        'sslmode' => 'prefer',
        'is_active' => true,
    ]
);

echo '=== target siap ==='.PHP_EOL;
printf("  %-20s %-6s %-8s %-18s %s".PHP_EOL, 'NAMA', 'PERAN', 'APP', 'DATABASE', 'READ-ONLY');
printf("  %-20s %-6s %-8s %-18s %s".PHP_EOL, $pgA->name, 'pg_a', '-', $pgA->database, '-');
printf("  %-20s %-6s %-8s %-18s %s".PHP_EOL, $pgBarmors->name, 'pg_b', 'armors', $pgBarmors->database, 'ods_ro');
printf("  %-20s %-6s %-8s %-18s %s".PHP_EOL, $pgBBuku->name, 'pg_b', 'buku', $pgBBuku->database, 'buku_ro');
echo PHP_EOL;

/* ------------------------------------------------------------------ */
/* 4. Dataset                                                           */
/* ------------------------------------------------------------------ */

// identity_mode = true: nama schema, tabel, dan kolom diambil dari metadata
// Oracle apa adanya, lalu ditulis huruf besar dan di-quote sebagai identifier
// PostgreSQL. Hasilnya "APPS"."BOOKS", bukan books.
$poHeaders = App\Models\Dataset::updateOrCreate(
    ['name' => 'po-headers'],
    [
        'description' => 'Header purchase order dari fixture armors.',
        'connection_id' => $mockArmors->id,
        'target_id' => $pgA->id,
        'sql' => 'SELECT PO_HEADER_ID, PO_NUMBER, STATUS_CODE, ORDER_DATE, LAST_UPDATE_DATE, APPROVED_FLAG FROM APPS.PO_HEADERS',
        'identity_mode' => true,
        'destination_schema' => 'APPS',
        'destination_table' => 'PO_HEADERS',
        'sync_mode' => App\Enums\SyncModeEnum::Full,
        'schedule_cron' => '0 * * * *',
        'timeout_seconds' => 60,
        'max_rows' => 100000,
        'batch_size' => 500,
        'current_version_no' => 1,
        'created_by' => $admin->id,
    ]
);

$books = App\Models\Dataset::updateOrCreate(
    ['name' => 'books'],
    [
        'description' => 'Katalog buku dari fixture buku.',
        'connection_id' => $mockBuku->id,
        'target_id' => $pgA->id,
        'sql' => 'SELECT ID, JUDUL, PENULIS, PENERBIT, TAHUN_TERBIT, ISBN, STOK, DESKRIPSI, CREATED_AT, UPDATED_AT FROM APPS.BOOKS',
        'identity_mode' => true,
        'destination_schema' => 'APPS',
        'destination_table' => 'BOOKS',
        'sync_mode' => App\Enums\SyncModeEnum::Full,
        'schedule_cron' => '0 * * * *',
        'timeout_seconds' => 60,
        'max_rows' => 100000,
        'batch_size' => 500,
        'current_version_no' => 1,
        'created_by' => $admin->id,
    ]
);

echo '=== dataset siap ==='.PHP_EOL;
echo '  po-headers -> "APPS"."PO_HEADERS"   (koneksi mock-armors)'.PHP_EOL;
echo '  books      -> "APPS"."BOOKS"        (koneksi mock-buku)'.PHP_EOL.PHP_EOL;

/* ------------------------------------------------------------------ */
/* 5. Langkah berikutnya                                                */
/* ------------------------------------------------------------------ */

echo '=== uid (dipakai kalau ingin memicu sinkronisasi via API) ==='.PHP_EOL;
echo '  target PG B buku : '.$pgBBuku->uid.PHP_EOL;
echo '  dataset books    : '.$books->uid.PHP_EOL.PHP_EOL;

echo '=== langkah selanjutnya ==='.PHP_EOL;
echo '  1. php artisan oracle-sync:sync --all'.PHP_EOL;
echo '  2. Copy A -> B lewat panel: http://127.0.0.1:8001/copy'.PHP_EOL;
echo PHP_EOL;
echo 'Catatan: tidak ada perintah artisan untuk copy. Copy hanya bisa lewat'.PHP_EOL;
echo 'panel, atau lewat CopyRunner di tinker:'.PHP_EOL;
echo '  app(App\Services\Copy\CopyRunner::class)->run('.PHP_EOL;
echo '      App\Models\Dataset::where("name", "books")->first(),'.PHP_EOL;
echo '      App\Models\Target::where("app_name", "buku")->first(),'.PHP_EOL;
echo '      App\Enums\RunTriggerEnum::Manual, null'.PHP_EOL;
echo '  );'.PHP_EOL;