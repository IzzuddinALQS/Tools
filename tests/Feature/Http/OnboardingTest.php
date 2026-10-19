<?php

namespace Tests\Feature\Http;

use App\Enums\UserRoleEnum;
use App\Http\Controllers\DemoSetupController;
use App\Models\Connection;
use App\Models\Dataset;
use App\Models\User;
use App\Services\Api\RuntimeStatus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\SyncTestCase;
use Tests\Support\InteractsWithDatabases;

/**
 * Fitur untuk pengguna baru: tombol data contoh, status jadwal otomatis di
 * Beranda, dan halaman Bantuan.
 */
class OnboardingTest extends SyncTestCase
{
    use InteractsWithDatabases;

    private function user(UserRoleEnum $role = UserRoleEnum::Admin): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_data_contoh_membuat_alur_lengkap_dan_mengambil_data(): void
    {
        $this->pgA();

        $response = $this->actingAs($this->user())->post('/contoh');

        $dataset = Dataset::where('name', DemoSetupController::DATASET_NAME)->firstOrFail();

        $response->assertRedirect(route('datasets.show', $dataset->id))
            ->assertSessionHas('success');

        $this->assertSame('mock', $dataset->connection->driver->value);
        $this->assertNotNull($dataset->fresh()->last_success_at, 'Data contoh harus langsung terambil.');
    }

    public function test_data_contoh_aman_diklik_berulang(): void
    {
        $this->pgA();
        $admin = $this->user();

        $this->actingAs($admin)->post('/contoh');
        $this->actingAs($admin)->post('/contoh')->assertSessionHas('success');

        $this->assertSame(1, Dataset::where('name', DemoSetupController::DATASET_NAME)->count());
        $this->assertSame(1, Connection::where('name', DemoSetupController::CONNECTION_NAME)->count());
    }

    public function test_data_contoh_gagal_tidak_meninggalkan_koneksi(): void
    {
        $this->pgA();

        // Tabel tujuan APPS.PO_HEADERS sudah dimiliki dataset lain.
        $this->dataset();

        $this->actingAs($this->user())->post('/contoh')->assertSessionHas('error');

        $this->assertFalse(Connection::where('name', DemoSetupController::CONNECTION_NAME)->exists());
    }

    public function test_data_contoh_hanya_untuk_admin(): void
    {
        $this->actingAs($this->user(UserRoleEnum::Viewer))->post('/contoh')->assertForbidden();
    }

    public function test_beranda_melaporkan_status_jadwal_otomatis(): void
    {
        $this->actingAs($this->user())->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('runtime.scheduler.known', false)
                ->where('runtime.queue.checked', config('queue.default') === 'database'));

        RuntimeStatus::recordHeartbeat('sync');

        $this->actingAs($this->user())->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('runtime.scheduler.known', true)
                ->where('runtime.scheduler.alive', true));
    }

    public function test_beranda_mendeteksi_antrean_tertahan(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'sync',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(10)->timestamp,
            'created_at' => now()->subMinutes(10)->timestamp,
        ]);

        $this->actingAs($this->user())->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('runtime.queue.checked', true)
                ->where('runtime.queue.pending', 1)
                ->where('runtime.queue.oldest_age', fn ($age) => $age >= 600));
    }

    public function test_halaman_bantuan_terbuka_untuk_semua_pengguna(): void
    {
        $this->actingAs($this->user(UserRoleEnum::Viewer))->get('/bantuan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Help'));
    }
}
