<?php

namespace Tests\Support;

use App\Enums\DriverEnum;
use App\Enums\SyncModeEnum;
use App\Enums\TargetKindEnum;
use App\Enums\UserRoleEnum;
use App\Models\Connection;
use App\Models\Dataset;
use App\Models\DatasetVersion;
use App\Models\Target;
use App\Models\User;
use App\Services\Fixture\FixtureCatalog;
use Illuminate\Database\Connection as DbConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helper untuk feature test mode mock.
 *
 * PG A dan PG B memakai PostgreSQL sungguhan, karena engine bergantung pada
 * fitur PostgreSQL: quoted identifier, schema, ALTER TABLE ... RENAME yang
 * transaksional, dan ON CONFLICT.
 */
trait InteractsWithDatabases
{
    /** @var array<string,DbConnection> */
    protected array $directConnections = [];

    /** @var array<string,DbConnection> */
    protected array $runtimeConnections = [];

    protected function adminUser(): User
    {
        return User::factory()->create(['role' => UserRoleEnum::Admin]);
    }

    protected function mockConnection(?string $fixtureSet = null): Connection
    {
        return Connection::create([
            'name' => 'mock-'.Str::lower(Str::random(8)),
            'driver' => DriverEnum::Mock,
            'fixture_set' => $fixtureSet ?: config('oracle-sync.fixtures.default_set'),
            'username' => 'mock_reader',
            'password' => 'mock-secret',
            'is_active' => true,
        ]);
    }

    /**
     * Koneksi dengan driver oracle yang SENGAJA tidak bisa dihubungi.
     */
    protected function unreachableOracleConnection(): Connection
    {
        return Connection::create([
            'name' => 'oracle-unreachable-'.Str::lower(Str::random(8)),
            'driver' => DriverEnum::Oracle,
            'host' => '127.0.0.1',
            // Port yang tidak ada listener-nya.
            'port' => 1,
            'service_name' => 'NOLISTENER',
            'username' => 'ods_reader',
            'password' => 'super-secret-value',
            'is_active' => true,
        ]);
    }

    protected function pgA(): Target
    {
        return Target::updateOrCreate(
            ['kind' => TargetKindEnum::PgA],
            [
                'name' => 'PG A (test)',
                'host' => env('TEST_PG_HOST', '127.0.0.1'),
                'port' => (int) env('TEST_PG_PORT', 5433),
                'database' => env('TEST_PG_A_DATABASE', 'ods_pg_a'),
                'username' => env('TEST_PG_USERNAME', 'postgres'),
                'password' => env('TEST_PG_PASSWORD', 'postgres'),
                'sslmode' => 'prefer',
                'is_active' => true,
            ],
        );
    }

    protected function pgB(string $appName = 'armors', ?string $database = null): Target
    {
        return Target::updateOrCreate(
            ['kind' => TargetKindEnum::PgB, 'app_name' => $appName],
            [
                'name' => 'PG B '.$appName.' (test)',
                'host' => env('TEST_PG_HOST', '127.0.0.1'),
                'port' => (int) env('TEST_PG_PORT', 5433),
                'database' => $database ?: env('TEST_PG_B_DATABASE', 'ods_pg_b_armors'),
                'username' => env('TEST_PG_USERNAME', 'postgres'),
                'password' => env('TEST_PG_PASSWORD', 'postgres'),
                'sslmode' => 'prefer',
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function dataset(array $attributes = [], ?Connection $connection = null): Dataset
    {
        $connection ??= $this->mockConnection();

        $dataset = Dataset::create(array_merge([
            'name' => 'dataset-'.Str::lower(Str::random(8)),
            'connection_id' => $connection->id,
            'target_id' => $this->pgA()->id,
            'sql' => 'SELECT PO_HEADER_ID, PO_NUMBER, STATUS_CODE, ORDER_DATE, LAST_UPDATE_DATE, APPROVED_FLAG FROM APPS.PO_HEADERS',
            'identity_mode' => true,
            'destination_schema' => 'APPS',
            'destination_table' => 'PO_HEADERS',
            'sync_mode' => SyncModeEnum::Full,
            'timeout_seconds' => 60,
            'max_rows' => 10000,
            'batch_size' => 2,
            'current_version_no' => 1,
        ], $attributes));

        /*
         * Versi awal dibuat di sini supaya fixture test sama realistis dengan
         * dataset yang dibuat lewat panel: setiap perubahan berikutnya menjadi
         * versi baru, dan versi lama tetap ada sebagai pembanding.
         */
        DatasetVersion::create([
            'dataset_id' => $dataset->id,
            'version_no' => 1,
            'sql' => $dataset->sql,
            'destination_schema' => $dataset->destination_schema,
            'destination_table' => $dataset->destination_table,
            'sync_mode' => $dataset->sync_mode->value,
            'watermark_column' => $dataset->watermark_column,
            'watermark_type' => $dataset->watermark_type,
            'key_columns' => $dataset->key_columns,
            'column_overrides' => $dataset->column_overrides,
            'schedule_cron' => $dataset->schedule_cron,
            'reconcile_cron' => $dataset->reconcile_cron,
            'timeout_seconds' => $dataset->timeout_seconds,
            'max_rows' => $dataset->max_rows,
            'batch_size' => $dataset->batch_size,
            'change_type' => 'create',
        ]);

        return $dataset;
    }

    protected function pgConnection(Target $target): DbConnection
    {
        $key = $target->uid;

        if (! isset($this->runtimeConnections[$key])) {
            $resolver = app(\App\Services\Pg\PgConnectionResolver::class);
            $this->runtimeConnections[$key] = $resolver->resolve($target);
        }

        return $this->runtimeConnections[$key];
    }

    /**
     * Membersihkan seluruh isi PG A dan PG B supaya setiap test terisolasi.
     *
     * Sengaja TIDAK bergantung pada baris `targets`: di dalam RefreshDatabase
     * baris itu berada dalam transaksi yang akan di-rollback, sehingga tidak
     * terlihat oleh test berikutnya dan schema pun tak pernah dibersihkan.
     */
    protected function resetTargets(): void
    {
        foreach ($this->testDatabases() as $database) {
            try {
                $connection = $this->connectionForDatabase($database);
                $connection->statement('DROP SCHEMA IF EXISTS "APPS" CASCADE');
                $connection->statement('DROP SCHEMA IF EXISTS "PUBLIC_TEST" CASCADE');
                $connection->statement('DROP SCHEMA IF EXISTS "_sync" CASCADE');
            } catch (\Throwable) {
                // Database mungkin belum ada; abaikan saja.
            }
        }
    }

    /**
     * @return array<int,string>
     */
    protected function testDatabases(): array
    {
        return array_values(array_unique([
            env('TEST_PG_A_DATABASE', 'ods_pg_a'),
            env('TEST_PG_B_DATABASE', 'ods_pg_b_armors'),
            env('TEST_PG_B2_DATABASE', 'ods_pg_b_reporting'),
        ]));
    }

    /**
     * Koneksi langsung ke salah satu database test, tanpa lewat tabel targets.
     */
    protected function connectionForDatabase(string $database): DbConnection
    {
        if (isset($this->directConnections[$database])) {
            return $this->directConnections[$database];
        }

        config(['database.connections.test_pg_'.$database => [
            'driver' => 'pgsql',
            'host' => env('TEST_PG_HOST', '127.0.0.1'),
            'port' => (int) env('TEST_PG_PORT', 5433),
            'database' => $database,
            'username' => env('TEST_PG_USERNAME', 'postgres'),
            'password' => env('TEST_PG_PASSWORD', 'postgres'),
            'charset' => 'utf8',
            'prefix' => '',
            'sslmode' => 'prefer',
        ]]);

        $name = 'test_pg_'.$database;
        DB::purge($name);

        /** @var DbConnection $connection */
        $connection = DB::connection($name);
        $connection->statement("SET TIME ZONE 'UTC'");

        return $this->directConnections[$database] = $connection;
    }

    /**
     * @return array<int,object>
     */
    protected function fetchTable(Target $target, string $schema, string $table): array
    {
        return $this->pgConnection($target)->select(sprintf(
            'SELECT * FROM "%s"."%s" ORDER BY 1',
            str_replace('"', '""', $schema),
            str_replace('"', '""', $table),
        ));
    }

    /**
     * @return array<int,string>
     */
    protected function tableNames(Target $target, string $schema): array
    {
        $rows = $this->pgConnection($target)->select(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name',
            [$schema],
        );

        return array_map(fn ($row) => $row->table_name, $rows);
    }

    /**
     * @return array<int,string>
     */
    protected function columnNames(Target $target, string $schema, string $table): array
    {
        $rows = $this->pgConnection($target)->select(
            'SELECT column_name AS name
               FROM information_schema.columns
              WHERE table_schema = ? AND table_name = ?
              ORDER BY ordinal_position',
            [$schema, $table],
        );

        return array_map(fn ($row) => (string) $row->name, $rows);
    }

    protected function loadArmorFixture(): FixtureCatalog
    {
        return FixtureCatalog::load(config('oracle-sync.fixtures.default_set'));
    }

    /**
     * Muat fixture dengan modifikasi baris, lalu daftarkan sebagai fake.
     *
     * @param  array<string,mixed>  $data
     */
    protected function fakeFixture(string $set, array $data): FixtureCatalog
    {
        return FixtureCatalog::fake($set, $data);
    }
}
