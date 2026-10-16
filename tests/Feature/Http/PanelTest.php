<?php

namespace Tests\Feature\Http;

use App\Enums\DriverEnum;
use App\Enums\SyncModeEnum;
use App\Enums\TargetKindEnum;
use App\Enums\UserRoleEnum;
use App\Models\Connection;
use App\Models\Dataset;
use App\Models\Target;
use App\Models\User;
use App\Services\Fixture\FixtureCatalog;
use Tests\Feature\SyncTestCase;
use Tests\Support\InteractsWithDatabases;

/**
 * Uji panel: login, hak akses admin, dan alur driver -> dataset -> preview.
 *
 * Mewarisi SyncTestCase supaya tiap test terisolasi: meta DB dibungkus
 * transaksi (RefreshDatabase) dan PG A / PG B benar-benar dibersihkan.
 */
class PanelTest extends SyncTestCase
{
    use InteractsWithDatabases;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRoleEnum::Admin]);
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => UserRoleEnum::Viewer]);
    }

    public function test_login_dibatasi_upaya(): void
    {
        /*
         * Panel ini satu-satunya jalan ke kredensial koneksi dan token API.
         * Tanpa throttle, brute force berjalan tanpa hambatan.
         */
        $admin = $this->admin();

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/login', [
                'email' => $admin->email,
                'password' => 'salah-sekali',
            ])->assertSessionHasErrors('email');
        }

        // Percobaan keenam, meski passwordnya benar, sudah ditolak.
        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_halaman_wajib_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/datasets')->assertRedirect(route('login'));
        $this->get('/drivers')->assertRedirect(route('login'));
    }

    public function test_admin_bisa_masuk_dan_membuka_halaman(): void
    {
        $admin = $this->admin();

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($admin);

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard'));
        $this->get('/datasets')->assertOk()->assertInertia(fn ($page) => $page->component('Datasets'));
        $this->get('/drivers')->assertOk()->assertInertia(fn ($page) => $page->component('Driver'));
        $this->get('/copy')->assertOk()->assertInertia(fn ($page) => $page->component('Copy'));
        $this->get('/history')->assertOk()->assertInertia(fn ($page) => $page->component('History'));
        $this->get('/pause')->assertOk()->assertInertia(fn ($page) => $page->component('Pause'));
    }

    public function test_viewer_tidak_bisa_membuka_halaman_driver(): void
    {
        $viewer = $this->viewer();

        $this->actingAs($viewer)->get('/drivers')->assertForbidden();

        // Tapi tetap boleh melihat halaman umum.
        $this->actingAs($viewer)->get('/')->assertOk();
        $this->actingAs($viewer)->get('/history')->assertOk();
    }

    public function test_viewer_tidak_bisa_membuat_koneksi(): void
    {
        $this->actingAs($this->viewer())
            ->post('/drivers/connections', ['name' => 'x', 'driver' => 'mock'])
            ->assertForbidden();
    }

    public function test_props_auth_dibagikan_ke_semua_halaman(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/')
            ->assertInertia(fn ($page) => $page
                ->where('auth.is_admin', true)
                ->where('auth.name', $admin->name)
                ->has('pause.paused'),
            );
    }

    public function test_membuat_koneksi_mock_lewat_panel(): void
    {
        $this->actingAs($this->admin())
            ->post('/drivers/connections', [
                'name' => 'mock-panel',
                'driver' => DriverEnum::Mock->value,
                'fixture_set' => 'armors',
                'username' => 'mock_reader',
                'password' => 'mock-secret',
            ])
            ->assertRedirect();

        $connection = Connection::where('name', 'mock-panel')->firstOrFail();

        $this->assertSame(DriverEnum::Mock, $connection->driver);
        $this->assertSame('mock-secret', $connection->password);
    }

    public function test_password_oracle_wajib_diisi(): void
    {
        $this->actingAs($this->admin())
            ->from('/drivers')
            ->post('/drivers/connections', [
                'name' => 'oracle-tanpa-password',
                'driver' => DriverEnum::Oracle->value,
                'host' => 'oracle.example',
                'username' => 'ods_reader',
                'password' => '',
            ])
            ->assertRedirect('/drivers')
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('connections', ['name' => 'oracle-tanpa-password']);
    }

    public function test_password_tidak_pernah_dikirim_ke_browser(): void
    {
        $admin = $this->admin();

        $connection = Connection::create([
            'name' => 'mock-secret-check',
            'driver' => DriverEnum::Mock,
            'fixture_set' => 'armors',
            'username' => 'mock_reader',
            'password' => 'rahasia-лети-x',
        ]);

        $target = Target::create([
            'name' => 'PG A panel',
            'kind' => TargetKindEnum::PgA,
            'host' => '127.0.0.1',
            'port' => 5433,
            'database' => 'ods_pg_a',
            'username' => 'postgres',
            'password' => 'rahasia-target-x',
            'sslmode' => 'prefer',
        ]);

        $response = $this->actingAs($admin)->get('/drivers')->assertOk();

        $content = $response->getContent();

        $this->assertStringNotContainsString('rahasia-лети-x', $content);
        $this->assertStringNotContainsString('rahasia-target-x', $content);

        // Yang terkirim hanya penanda "sudah diisi".
        $this->assertStringContainsString('has_password', $content);
        $this->assertNotNull($connection->fresh()->password);
        $this->assertNotNull($target->fresh()->password);
    }

    public function test_tes_koneksi_mock_berhasil(): void
    {
        $connection = $this->mockConnection();

        $this->actingAs($this->admin())
            ->from('/drivers')
            ->post("/drivers/connections/{$connection->id}/test")
            ->assertRedirect('/drivers')
            ->assertSessionHasNoErrors();

        $this->assertSame('ok', $connection->fresh()->last_test_status);
    }

    public function test_membuat_target_pg_b(): void
    {
        $this->actingAs($this->admin())
            ->post('/drivers/targets', [
                'name' => 'PG B armors',
                'kind' => TargetKindEnum::PgB->value,
                'app_name' => 'armors',
                'host' => '127.0.0.1',
                'port' => 5433,
                'database' => 'ods_pg_b_armors',
                'username' => 'postgres',
                'password' => 'postgres',
            ])
            ->assertRedirect();

        $target = Target::where('name', 'PG B armors')->firstOrFail();

        $this->assertTrue($target->isPgB());
        $this->assertSame('armors', $target->app_name);
    }

    public function test_hanya_ada_satu_pg_a(): void
    {
        // PG A pertama.
        $this->pgA();

        $this->actingAs($this->admin())
            ->post('/drivers/targets', [
                'name' => 'PG A kedua',
                'kind' => TargetKindEnum::PgA->value,
                'host' => '127.0.0.1',
                'port' => 5433,
                'database' => 'ods_pg_a',
                'username' => 'postgres',
                'password' => 'postgres',
            ])
            ->assertSessionHasErrors('kind');

        $this->assertSame(1, Target::where('kind', TargetKindEnum::PgA->value)->count());
    }

    public function test_preview_menampilkan_tipe_dan_peringatan(): void
    {
        $connection = $this->mockConnection();

        $response = $this->actingAs($this->admin())->postJson('/datasets/preview', [
            'connection_id' => $connection->id,
            'sql' => 'SELECT PO_HEADER_ID, PO_NUMBER, ORDER_DATE FROM APPS.PO_HEADERS',
        ]);

        $response->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('preview.identity_mode', true)
            ->assertJsonPath('preview.source_table', 'APPS.PO_HEADERS')
            ->assertJsonPath('preview.saved', false);

        $types = collect($response->json('preview.columns'))->pluck('pg_type', 'name');

        $this->assertSame('numeric(12,0)', $types['PO_HEADER_ID']);
        $this->assertSame('varchar(30)', $types['PO_NUMBER']);
        $this->assertSame('timestamp(0)', $types['ORDER_DATE']);

        // Tidak ada tabel yang dibuat oleh Preview.
        $this->assertSame([], $this->tableNames($this->pgA(), 'APPS'));
    }

    public function test_preview_menolak_dml(): void
    {
        $connection = $this->mockConnection();

        $this->actingAs($this->admin())->postJson('/datasets/preview', [
            'connection_id' => $connection->id,
            'sql' => 'DELETE FROM APPS.PO_HEADERS',
        ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_preview_wajib_memberi_nama_tujuan_untuk_join(): void
    {
        $connection = $this->mockConnection();

        $this->actingAs($this->admin())->postJson('/datasets/preview', [
            'connection_id' => $connection->id,
            'sql' => 'SELECT h.PO_NUMBER FROM APPS.PO_HEADERS h JOIN APPS.PO_LINES l ON l.PO_HEADER_ID = h.PO_HEADER_ID',
        ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_membuat_dataset_melalui_panel(): void
    {
        $connection = $this->mockConnection();
        $target = $this->pgA();

        $this->actingAs($this->admin())
            ->post('/datasets', [
                'name' => 'po-headers-panel',
                'connection_id' => $connection->id,
                'target_id' => $target->id,
                'sql' => 'SELECT PO_HEADER_ID, PO_NUMBER, STATUS_CODE FROM APPS.PO_HEADERS',
                'sync_mode' => SyncModeEnum::Full->value,
                'timeout_seconds' => 60,
                'max_rows' => 1000,
                'batch_size' => 100,
                'schedule_cron' => '0 * * * *',
            ])
            ->assertSessionHasNoErrors();

        $dataset = Dataset::where('name', 'po-headers-panel')->firstOrFail();

        // Nama tujuan diambil dari metadata Oracle, bukan dari ketikan user.
        $this->assertSame('APPS', $dataset->destination_schema);
        $this->assertSame('PO_HEADERS', $dataset->destination_table);
        $this->assertTrue($dataset->identity_mode);

        // Versi pertama tercatat.
        $this->assertSame(1, $dataset->current_version_no);
        $this->assertSame(1, $dataset->versions()->count());
    }

    public function test_mengubah_dataset_membuat_versi_baru(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->admin())
            ->put("/datasets/{$dataset->id}", [
                'name' => $dataset->name,
                'connection_id' => $dataset->connection_id,
                'target_id' => $dataset->target_id,
                'sql' => 'SELECT PO_HEADER_ID, PO_NUMBER, STATUS_CODE, VENDOR_ID FROM APPS.PO_HEADERS',
                'sync_mode' => SyncModeEnum::Full->value,
                'timeout_seconds' => 60,
                'max_rows' => 1000,
                'batch_size' => 100,
                'note' => 'tambah kolom VENDOR_ID',
            ])
            ->assertSessionHasNoErrors();

        $dataset->refresh();

        $this->assertSame(2, $dataset->current_version_no);
        $this->assertSame(2, $dataset->versions()->count());
        $this->assertStringContainsString('VENDOR_ID', $dataset->sql);

        // Versi 1 masih menyimpan SQL lama: ada "sebelum".
        $this->assertStringNotContainsString('VENDOR_ID', $dataset->versions()->where('version_no', 1)->first()->sql);
        // Relasi versions() sudah membawa ORDER BY version_no ASC. Menambah
        // orderByDesc() TIDAK mengubah urutan karena klausa pertama tetap
        // dominan, jadi harus reorder().
        $this->assertSame(
            'tambah kolom VENDOR_ID',
            $dataset->versions()->reorder('version_no', 'desc')->first()->note,
        );
    }

    public function test_dataset_baru_wajib_memberi_nama_tujuan_untuk_join(): void
    {
        $this->actingAs($this->admin())
            ->from('/datasets/create')
            ->post('/datasets', [
                'name' => 'join-tanpa-nama',
                'connection_id' => $this->mockConnection()->id,
                'target_id' => $this->pgA()->id,
                'sql' => 'SELECT h.PO_NUMBER FROM APPS.PO_HEADERS h JOIN APPS.PO_LINES l ON l.PO_HEADER_ID = h.PO_HEADER_id',
                'sync_mode' => SyncModeEnum::Full->value,
                'timeout_seconds' => 60,
                'max_rows' => 1000,
                'batch_size' => 100,
            ])
            ->assertSessionHasErrors('sql');

        $this->assertDatabaseMissing('datasets', ['name' => 'join-tanpa-nama']);
    }

    public function test_menjalankan_sinkronisasi_dari_panel(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->admin())
            ->from("/datasets/{$dataset->id}")
            ->post("/datasets/{$dataset->id}/run")
            ->assertSessionHas('success');

        $this->assertContains('PO_HEADERS', $this->tableNames($dataset->target, 'APPS'));
    }

    public function test_kill_switch_melalui_panel(): void
    {
        $this->actingAs($this->admin())
            ->from('/pause')
            ->post('/pause', ['reason' => 'pemeliharaan'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('settings', ['key' => 'global_pause']);

        $this->actingAs($this->admin())
            ->from('/pause')
            ->delete('/pause')
            ->assertSessionHas('success');
    }

    public function test_kill_switch_menahan_run_dari_panel(): void
    {
        $dataset = $this->dataset();

        app(\App\Services\Guard\KillSwitch::class)->pause();

        $this->actingAs($this->admin())
            ->from("/datasets/{$dataset->id}")
            ->post("/datasets/{$dataset->id}/run")
            ->assertSessionHas('error');

        $this->assertSame([], $this->tableNames($dataset->target, 'APPS'));
    }

    public function test_copy_dari_panel(): void
    {
        $dataset = $this->dataset();
        $targetB = $this->pgB('armors');

        $this->actingAs($this->admin())->post("/datasets/{$dataset->id}/run");

        $this->actingAs($this->admin())
            ->from('/copy')
            ->post('/copy', ['dataset_id' => $dataset->id, 'target_id' => $targetB->id])
            ->assertSessionHas('success');

        $this->assertContains('PO_HEADERS', $this->tableNames($targetB, 'APPS'));
    }

    public function test_copy_ke_pg_a_ditolak(): void
    {
        $dataset = $this->dataset();
        $targetA = $this->pgA();

        $this->actingAs($this->admin())
            ->from('/copy')
            ->post('/copy', ['dataset_id' => $dataset->id, 'target_id' => $targetA->id])
            ->assertSessionHasErrors('target_id');
    }

    public function test_dataset_hanya_boleh_ditulis_ke_pg_a(): void
    {
        $targetB = $this->pgB('armors');

        $this->actingAs($this->admin())
            ->from('/datasets/create')
            ->post('/datasets', [
                'name' => 'tulis-ke-pg-b',
                'connection_id' => $this->mockConnection()->id,
                'target_id' => $targetB->id,
                'sql' => 'SELECT PO_HEADER_ID FROM APPS.PO_HEADERS',
                'sync_mode' => SyncModeEnum::Full->value,
                'timeout_seconds' => 60,
                'max_rows' => 1000,
                'batch_size' => 100,
            ])
            ->assertSessionHasErrors('sql');

        $this->assertDatabaseMissing('datasets', ['name' => 'tulis-ke-pg-b']);
    }
}
