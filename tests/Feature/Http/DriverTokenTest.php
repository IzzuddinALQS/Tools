<?php

namespace Tests\Feature\Http;

use App\Enums\UserRoleEnum;
use App\Models\ApiToken;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\SyncTestCase;
use Tests\Support\InteractsWithDatabases;

/**
 * Uji pengelolaan token API dari halaman Driver.
 *
 * Properti keamanan yang paling penting diuji di sini: nilai asli token hanya
 * boleh muncul SATU KALI. Kalau bocor di permintaan berikutnya, seluruh cara
 * penyimpanan hash jadi tidak berarti.
 */
class DriverTokenTest extends SyncTestCase
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

    public function test_hanya_admin_yang_bisa_terbitkan_token(): void
    {
        $dataset = $this->dataset();
        $viewer = $this->viewer();

        $this->actingAs($viewer)
            ->post('/drivers/tokens', [
                'name' => 'x', 'app_name' => 'armors', 'dataset_id' => $dataset->id,
                'rate_limit_per_minute' => 10,
            ])
            ->assertForbidden();

        $this->assertSame(0, ApiToken::count());
    }

    public function test_tamu_tidak_bisa_membuka_halaman_driver(): void
    {
        $this->post('/drivers/tokens', [])->assertRedirect(route('login'));
    }

    public function test_admin_terbitkan_token_dan_nilai_asli_hanya_muncul_satu_kali(): void
    {
        $admin = $this->admin();
        $dataset = $this->dataset(['name' => 'po-headers']);

        $this->actingAs($admin)
            ->post('/drivers/tokens', [
                'name' => 'laptop-budi',
                'app_name' => 'armors',
                'dataset_id' => $dataset->id,
                'rate_limit_per_minute' => 10,
            ])
            ->assertRedirect();

        $token = ApiToken::query()->firstOrFail();

        $this->assertSame('armors', $token->app_name);
        $this->assertSame('laptop-budi', $token->name);
        $this->assertSame($dataset->id, $token->dataset_id);
        $this->assertSame($admin->id, $token->created_by);
        $this->assertNotNull($token->uid);

        // Permintaan berikutnya (setelah redirect) masih membawa nilainya. Ini harus
        // jadi satu request: flash hanya ada untuk render PERTAMA, jadi diambil
        // harus dari respons yang sama yang dia assertion-kan.
        $first = $this->actingAs($admin)->get('/drivers');

        $first->assertOk()->assertInertia(fn ($page) => $page
            ->component('Driver')
            ->where('issuedToken.dataset', 'po-headers')
            ->where('issuedToken.dataset_uid', $dataset->uid)
            ->where('issuedToken.target_uid', $dataset->target->uid)
            ->where('issuedToken.app_name', 'armors')
            ->has('issuedToken.plain')
            ->has('issuedToken.uid')
        );

        $plain = $first->viewData('page')['props']['issuedToken']['plain'] ?? null;

        $this->assertNotNull($plain, 'Nilai asli harus tersedia pada render pertama.');
        $this->assertSame(64, strlen($plain));

        // Dan harus hilang pada permintaan berikutnya.
        $this->actingAs($admin)
            ->get('/drivers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('issuedToken', null));

        $second = $this->actingAs($admin)->get('/drivers')->getContent();

        $this->assertStringNotContainsString($plain, $second);

        // Tidak boleh bocor lewat halaman lain.
        $dashboard = $this->actingAs($admin)->get('/');
        $this->assertStringNotContainsString($plain, $dashboard->getContent());
    }

    public function test_token_tidak_ada_plaintext_di_tabel_sessions(): void
    {
        /*
     *ession driver database tanpa enkripsi akan menyimpan isi flash apa
     * adanya. Karena itu nilai token dienkripsi dengan APP_KEY sebelum
     * masuk session.
     */
        putenv('SESSION_DRIVER=database');
        $_ENV['SESSION_DRIVER'] = 'database';
        $_SERVER['SESSION_DRIVER'] = 'database';
        config(['session.driver' => 'database', 'session.encrypt' => false]);
        session()->setDefaultDriver('database');
        app()->forgetInstance('session');

        $admin = $this->admin();
        $dataset = $this->dataset();

        $this->actingAs($admin)->post('/drivers/tokens', [
            'name' => 'a', 'app_name' => 'armors', 'dataset_id' => $dataset->id,
            'rate_limit_per_minute' => 10,
        ]);

        $token = ApiToken::query()->firstOrFail();

        // Nilai asli, seperti yang akan sampai ke browser.
        $rendered = $this->actingAs($admin)->get('/drivers');
        $plain = $rendered->viewData('page')['props']['issuedToken']['plain'] ?? null;

        $this->assertNotNull($plain);

        $stored = base64_decode((string) DB::table('sessions')->orderByDesc('last_activity')->value('payload'));

        $this->assertStringContainsString('issuedToken', $stored, 'Flash memang lewat session.');
        $this->assertStringNotContainsString(
            $plain,
            $stored,
            'Nilai asli token tidak boleh tersimpan di tabel sessions.',
        );
        $this->assertStringNotContainsString(
            $token->token_hash,
            $stored,
            'Hash token juga tidak perlu ada di session.',
        );
    }

    public function test_token_ditolak_saat_produksi_tanpa_session_encrypt(): void
    {
        config(['app.env' => 'production', 'session.encrypt' => false]);

        $admin = $this->admin();
        $dataset = $this->dataset();

        $this->actingAs($admin)
            ->post('/drivers/tokens', [
                'name' => 'a', 'app_name' => 'armors', 'dataset_id' => $dataset->id,
                'rate_limit_per_minute' => 10,
            ])
            ->assertSessionHasErrors('token');

        $this->assertSame(0, ApiToken::count());
    }

    public function test_hash_token_tidak_pernah_dikirim_ke_browser(): void
    {
        $admin = $this->admin();
        $dataset = $this->dataset();

        $this->actingAs($admin)->post('/drivers/tokens', [
            'name' => 'a', 'app_name' => 'armors', 'dataset_id' => $dataset->id,
            'rate_limit_per_minute' => 10,
        ]);

        $token = ApiToken::query()->firstOrFail();

        $response = $this->actingAs($admin)->get('/drivers');

        $this->assertStringNotContainsString($token->token_hash, $response->getContent());
        $this->assertStringNotContainsString('token_hash', $response->getContent());
    }

    public function test_validasi_menolak_app_name_berhuruf_besar(): void
    {
        $admin = $this->admin();
        $dataset = $this->dataset();

        $this->actingAs($admin)
            ->post('/drivers/tokens', [
                'name' => 'a', 'app_name' => 'Armors', 'dataset_id' => $dataset->id,
                'rate_limit_per_minute' => 10,
            ])
            ->assertSessionHasErrors('app_name');

        $this->assertSame(0, ApiToken::count());
    }

    public function test_validasi_menolak_ip_tidak_sah(): void
    {
        $admin = $this->admin();
        $dataset = $this->dataset();

        $this->actingAs($admin)
            ->post('/drivers/tokens', [
                'name' => 'a', 'app_name' => 'armors', 'dataset_id' => $dataset->id,
                'rate_limit_per_minute' => 10,
                'allowed_ips' => '10.0.0.5, bukan-ip',
            ])
            ->assertSessionHasErrors('allowed_ips');

        $this->assertSame(0, ApiToken::count());
    }

    public function test_validasi_menolak_dataset_tidak_ada(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/drivers/tokens', [
                'name' => 'a', 'app_name' => 'armors', 'dataset_id' => 99999,
                'rate_limit_per_minute' => 10,
            ])
            ->assertSessionHasErrors('dataset_id');
    }

    public function test_daftar_ip_dinormalisasi(): void
    {
        $admin = $this->admin();
        $dataset = $this->dataset();

        $this->actingAs($admin)->post('/drivers/tokens', [
            'name' => 'a', 'app_name' => 'armors', 'dataset_id' => $dataset->id,
            'rate_limit_per_minute' => 10,
            'allowed_ips' => "10.0.0.5\n10.0.0.6, 10.0.0.5",
        ]);

        $token = ApiToken::query()->firstOrFail();

        $this->assertSame(['10.0.0.5', '10.0.0.6'], $token->allowed_ips);
    }

    public function test_admin_bisa_mencabut_token(): void
    {
        $admin = $this->admin();
        $dataset = $this->dataset();

        $this->actingAs($admin)->post('/drivers/tokens', [
            'name' => 'a', 'app_name' => 'armors', 'dataset_id' => $dataset->id,
            'rate_limit_per_minute' => 10,
        ]);

        $token = ApiToken::query()->firstOrFail();

        $this->actingAs($admin)
            ->post("/drivers/tokens/{$token->id}/revoke", ['reason' => ' laptop hilang '])
            ->assertRedirect();

        $token->refresh();

        $this->assertNotNull($token->revoked_at);
        $this->assertSame('laptop hilang', $token->revoked_reason);
    }

    public function test_viewer_tidak_bisa_mencabut_token(): void
    {
        $admin = $this->admin();
        $viewer = $this->viewer();
        $dataset = $this->dataset();

        $this->actingAs($admin)->post('/drivers/tokens', [
            'name' => 'a', 'app_name' => 'armors', 'dataset_id' => $dataset->id,
            'rate_limit_per_minute' => 10,
        ]);

        $token = ApiToken::query()->firstOrFail();

        $this->actingAs($viewer)
            ->post("/drivers/tokens/{$token->id}/revoke")
            ->assertForbidden();

        $this->assertNull($token->fresh()->revoked_at);
    }

    public function test_token_yang_sudah_dicabut_tidak_bisa_dicabut_lagi(): void
    {
        $admin = $this->admin();
        $dataset = $this->dataset();

        $this->actingAs($admin)->post('/drivers/tokens', [
            'name' => 'a', 'app_name' => 'armors', 'dataset_id' => $dataset->id,
            'rate_limit_per_minute' => 10,
        ]);

        $token = ApiToken::query()->firstOrFail();

        $this->actingAs($admin)->post("/drivers/tokens/{$token->id}/revoke");

        $pertama = $token->fresh()->revoked_at;

        $this->actingAs($admin)
            ->post("/drivers/tokens/{$token->id}/revoke")
            ->assertSessionHasErrors('token');

        $this->assertEquals($pertama, $token->fresh()->revoked_at);
    }

    public function test_halaman_driver_menampilkan_daftar_token_tanpa_hash(): void
    {
        $admin = $this->admin();
        $dataset = $this->dataset(['name' => 'po-headers']);

        $this->actingAs($admin)->post('/drivers/tokens', [
            'name' => 'laptop', 'app_name' => 'armors', 'dataset_id' => $dataset->id,
            'rate_limit_per_minute' => 25,
        ]);

        $this->actingAs($admin)
            ->get('/drivers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Driver')
                ->has('tokens', 1)
                ->where('tokens.0.app_name', 'armors')
                ->where('tokens.0.name', 'laptop')
                ->where('tokens.0.dataset', 'po-headers')
                ->where('tokens.0.rate_limit_per_minute', 25)
                ->where('tokens.0.is_active', true)
                ->where('tokens.0.uid', ApiToken::query()->firstOrFail()->uid)
                ->has('tokenableDatasets')
            );
    }
}