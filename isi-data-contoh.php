<?php

use App\Enums\DriverEnum;
use App\Enums\SyncModeEnum;
use App\Enums\TargetKindEnum;
use App\Enums\UserRoleEnum;
use App\Models\ApiToken;
use App\Models\Connection;
use App\Models\Dataset;
use App\Models\Target;
use App\Models\User;

/*
 * Isi data contoh supaya panel bisa dilihat tanpa Oracle.
 *
 * Jalankan:
 *     php artisan tinker
 *     require base_path('buat-akun.php');
 *     require base_path('isi-data-contoh.php');
 */

/*
 * Isi file ini memuat password admin yang sudah diketahui, jadi tidak boleh
 * jalan di produksi. Selain itu, isinya menimpa data driver dan dataset yang
 * mungkin sedang dipakai sungguhan.
 */
if (app()->environment('production')) {
    fwrite(STDERR, PHP_EOL);
    fwrite(STDERR, 'DITOLAK: data contoh tidak boleh dipasang di APP_ENV=production.'.PHP_EOL);
    fwrite(STDERR, 'Skrip ini menimpa koneksi, target, dataset, dan menerbitkan token.'.PHP_EOL.PHP_EOL);

    return;
}

$email = 'admin@ods.local';
$password = 'ods12345';
$name = 'Admin Oracle Data Sync';

User::query()->where('email', '!=', $email)->update(['role' => UserRoleEnum::Viewer->value]);

$user = User::firstOrNew(['email' => $email]);

$user->forceFill([
    'name' => $name,
    'password' => $password,
    'role' => UserRoleEnum::Admin,
])->save();

echo '=== akun siap ===', PHP_EOL;
echo 'email    : '.$user->email, PHP_EOL;
echo 'password : '.$password, PHP_EOL, PHP_EOL;

/* ------------------------------------------------------------------ */

$connection = Connection::updateOrCreate(
    ['name' => 'mock-armors'],
    [
        'description' => 'Fixture lokal, tidak menyentuh Oracle.',
        'driver' => DriverEnum::Mock,
        'fixture_set' => 'armors',
        'username' => 'mock_reader',
        'password' => 'mock-secret',
        'is_active' => true,
    ]
);

$pgA = Target::updateOrCreate(
    ['kind' => TargetKindEnum::PgA],
    [
        'name' => 'PG A (sinkronisasi)',
        'description' => 'Hasil query Oracle, hanya ditulis oleh tool ini.',
        'host' => '127.0.0.1',
        'port' => 5433,
        'database' => 'ods_pg_a',
        'username' => 'postgres',
        'password' => 'postgres',
        'sslmode' => 'prefer',
        'is_active' => true,
    ]
);

Target::updateOrCreate(
    ['kind' => TargetKindEnum::PgB, 'app_name' => 'armors'],
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

echo '=== driver siap ===', PHP_EOL;
echo 'connection: ', $connection->name, PHP_EOL;
echo 'pg_a       : ', $pgA->name, ' (uid ', $pgA->uid, ')', PHP_EOL, PHP_EOL;

/* ------------------------------------------------------------------ */

$dataset = Dataset::updateOrCreate(
    ['name' => 'po-headers'],
    [
        'description' => 'Header purchase order dari APPS.PO_HEADERS.',
        'connection_id' => $connection->id,
        'target_id' => $pgA->id,
        'sql' => 'SELECT PO_HEADER_ID, PO_NUMBER, STATUS_CODE, ORDER_DATE, LAST_UPDATE_DATE, APPROVED_FLAG FROM APPS.PO_HEADERS',
        'identity_mode' => true,
        'destination_schema' => 'APPS',
        'destination_table' => 'PO_HEADERS',
        'sync_mode' => SyncModeEnum::Full,
        'schedule_cron' => '0 * * * *',
        'timeout_seconds' => 60,
        'max_rows' => 100000,
        'batch_size' => 500,
        'current_version_no' => 1,
        'created_by' => $user->id,
    ]
);

$dataset2 = Dataset::updateOrCreate(
    ['name' => 'gl-summary'],
    [
        'description' => 'Ringkasan jurnal umum.',
        'connection_id' => $connection->id,
        'target_id' => $pgA->id,
        'sql' => 'SELECT JOURNAL_HEADER_ID, PERIOD_NAME, TOTAL_DEBIT FROM APPS.GL_JOURNAL_HEADERS',
        'identity_mode' => false,
        'destination_schema' => 'PUBLIC_TEST',
        'destination_table' => 'GL_SUMMARY',
        'sync_mode' => SyncModeEnum::Incremental,
        'schedule_cron' => '*/30 * * * *',
        'reconcile_cron' => '0 2 * * *',
        'watermark_column' => 'LAST_UPDATE_DATE',
        'watermark_type' => 'TIMESTAMP',
        'key_columns' => ['JOURNAL_HEADER_ID'],
        'timeout_seconds' => 120,
        'max_rows' => 200000,
        'batch_size' => 1000,
        'current_version_no' => 1,
        'created_by' => $user->id,
    ]
);

echo '=== dataset siap ===', PHP_EOL;

foreach ([$dataset, $dataset2] as $item) {
    echo '  - ', str_pad($item->name, 14), $item->uid, PHP_EOL;
}

echo PHP_EOL;

/* ------------------------------------------------------------------ */

// Hanya buat token kalau belum ada, supaya tidak menumpuk tiap kali dijalankan.
if (ApiToken::query()->where('app_name', 'armors')->doesntExist()) {
    [$token, $plain] = ApiToken::issue([
        'name' => 'server produksi',
        'app_name' => 'armors',
        'target_id' => $dataset->target_id,
        'dataset_id' => $dataset->id,
        'rate_limit_per_minute' => 10,
        'allowed_ips' => ['10.0.0.5'],
        'created_by' => $user->id,
    ]);

    echo '=== token siap ===', PHP_EOL;
    echo 'token sekali tampil ini saja:', PHP_EOL;
    echo '  ', $plain, PHP_EOL;
    echo 'POST /api/v1/targets/', $token->target->uid, '/datasets/', $token->dataset->uid, '/refresh', PHP_EOL;
}