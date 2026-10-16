<?php

namespace Tests\Feature\Http;

use App\Enums\DatasetStatusEnum;
use App\Enums\RunTriggerEnum;
use App\Jobs\SyncDatasetJob;
use App\Models\ApiToken;
use App\Models\Dataset;
use App\Models\Target;
use App\Services\Guard\KillSwitch;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\SyncTestCase;
use Tests\Support\InteractsWithDatabases;

/**
 * Uji API Tahap 2: POST /api/v1/targets/{uid}/datasets/{uid}/refresh
 *
 * Fokus test ini bukan pada isi data (itu sudah-covered test engine), tapi pada
 * batas wewenang: siapa boleh memicu dataset mana. Token hanya berlaku untuk
 * satu kombinasi target + dataset, jadi diuji juga bahwa token milik ARMORS
 * tidak bisa menyentuh dataset milik aplikasi lain.
 */
class ApiRefreshTest extends SyncTestCase
{
    use InteractsWithDatabases;

    private const URL = '/api/v1/targets/%s/datasets/%s/refresh';

    /**
     * @return array{0:ApiToken,1:string}
     */
    private function issueToken(Dataset $dataset, string $appName = 'armors', int $rateLimit = 10): array
    {
        return ApiToken::issue([
            'name' => 'test-token',
            'app_name' => $appName,
            'target_id' => $dataset->target_id,
            'dataset_id' => $dataset->id,
            'rate_limit_per_minute' => $rateLimit,
        ]);
    }

    private function url(Dataset $dataset, ?Target $target = null): string
    {
        $target ??= Target::findOrFail($dataset->target_id);

        return sprintf(self::URL, $target->uid, $dataset->uid);
    }

    public function test_menolak_permintaan_tanpa_token(): void
    {
        $dataset = $this->dataset();

        $this->postJson($this->url($dataset))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Token tidak ditemukan. Kirim header: Authorization: Bearer <token>.');
    }

    public function test_menolak_token_yang_tidak_dikenal(): void
    {
        $dataset = $this->dataset();

        $this->withHeader('Authorization', 'Bearer token-palsu')
            ->postJson($this->url($dataset))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Token tidak dikenal.');
    }

    public function test_menerima_header_x_api_token(): void
    {
        Queue::fake();

        $dataset = $this->dataset();
        [$token, $plain] = $this->issueToken($dataset);

        $this->withHeader('X-Api-Token', $plain)
            ->postJson($this->url($dataset))
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('trigger', 'api');

        $this->assertNotNull($token->fresh()->last_used_at, 'last_used_at harus tercatat.');
    }

    public function test_menolak_token_yang_sudah_dicabut(): void
    {
        $dataset = $this->dataset();
        [, $plain] = $this->issueToken($dataset);

        ApiToken::findByPlainToken($plain)?->revoke('kunci dicabut');

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson($this->url($dataset))
            ->assertForbidden()
            ->assertJsonPath('message', 'Token sudah dicabut.');
    }

    public function test_token_tidak_boleh_memicu_dataset_lain(): void
    {
        Queue::fake();

        $milik_armors = $this->dataset(['name' => 'po-headers']);
        $milik_lain = $this->dataset([
            'name' => 'laporan-bulanan',
            // Satu tabel tujuan hanya boleh dimiliki satu dataset.
            'destination_table' => 'GL_SUMMARY',
        ]);

        [, $plain] = $this->issueToken($milik_armors, 'armors');

        // UUID dataset milik aplikasi lain, tapi token milik armors.
        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson($this->url($milik_lain))
            ->assertForbidden()
            ->assertJsonPath('message', 'Token ini tidak berwenang untuk target/dataset tersebut.');

        Queue::assertNothingPushed();
    }

    public function test_token_tidak_boleh_menyeber_cross_target(): void
    {
        Queue::fake();

        $dataset = $this->dataset();
        $pgB = $this->pgB('armors');

        [, $plain] = $this->issueToken($dataset);

        // Target di URL diganti ke PG B, sementara token menunjuk PG A.
        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson(sprintf(self::URL, $pgB->uid, $dataset->uid))
            ->assertForbidden();
    }

    public function test_dataset_tidak_ditemukan_memberi_404(): void
    {
        $dataset = $this->dataset();
        [, $plain] = $this->issueToken($dataset);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson(sprintf(self::URL, $dataset->target->uid, '00000000-0000-0000-0000-000000000000'))
            ->assertNotFound();
    }

    public function test_menolak_ip_di_luar_daftar(): void
    {
        Queue::fake();

        $dataset = $this->dataset();

        [$token, $plain] = $this->issueToken($dataset);
        $token->forceFill(['allowed_ips' => ['10.0.0.1']])->save();

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson($this->url($dataset))
            ->assertForbidden()
            ->assertJsonPath('message', 'Alamat IP tidak diizinkan untuk token ini.');

        Queue::assertNothingPushed();
    }

    public function test_menolak_saat_kill_switch_aktif(): void
    {
        Queue::fake();

        $dataset = $this->dataset();
        [, $plain] = $this->issueToken($dataset);

        app(KillSwitch::class)->pause(null, 'kunci dicabut');

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson($this->url($dataset))
            ->assertStatus(409)
            ->assertJsonPath('status', 'paused');

        Queue::assertNothingPushed();
    }

    public function test_menolak_dataset_yang_dijeda(): void
    {
        Queue::fake();

        $dataset = $this->dataset();
        [, $plain] = $this->issueToken($dataset);

        $dataset->forceFill(['status' => DatasetStatusEnum::Paused])->save();

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson($this->url($dataset))
            ->assertStatus(409)
            ->assertJsonPath('status', 'skipped');
    }

    public function test_job_punya_pelindung_tumpang_tindih(): void
    {
        /*
         * Controller sengaja TIDAK mengecek "sedang berjalan" sebelum
         * mengantrekan job, karena satu-satunya cara mengecek cache lock tanpa
         * mengambilnya adalah dengan mengambilnya. Jaminan "tidak berjalan
         * ganda" karena itu dipusatkan di sini, dan test inilah yang menjaganya.
         */
        $dataset = $this->dataset();
        $job = new SyncDatasetJob($dataset->id, RunTriggerEnum::Api->value);

        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertTrue($middleware[0]->dontReleaseIsEnabled ?? true);
    }

    public function test_dua_permintaan_beruntun_masih_berhasil(): void
    {
        Queue::fake();

        $dataset = $this->dataset();
        [, $plain] = $this->issueToken($dataset);

        // Keduanya diterima; job kedua yang dibuang, bukan permintaannya.
        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson($this->url($dataset))->assertAccepted();
        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson($this->url($dataset))->assertAccepted();

        Queue::assertPushed(SyncDatasetJob::class, 2);
    }

    public function test_membatasi_laju_per_token(): void
    {
        Queue::fake();

        $dataset = $this->dataset();
        [, $plain] = $this->issueToken($dataset, 'armors', rateLimit: 2);

        $url = $this->url($dataset);

        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson($url)->assertAccepted();
        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson($url)->assertAccepted();

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson($url)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Terlalu banyak permintaan. Coba lagi nanti.');

        // Dua permintaan yang sempat lolos = dua job sync.
        Queue::assertPushed(\App\Jobs\SyncDatasetJob::class, 2);
    }

    public function test_token_disimpan_sebagai_hash(): void
    {
        $dataset = $this->dataset();
        [$token, $plain] = $this->issueToken($dataset);

        $this->assertNotSame($plain, $token->token_hash);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertNull($token->plain_token ?? null, 'Nilai asli tidak boleh ada di model.');

        // Nilai asli tidak boleh bocor lewat serialisasi.
        $this->assertStringNotContainsString($plain, $token->toJson());
    }

    public function test_perintah_ods_token_terbitkan_dan_cabut(): void
    {
        $dataset = $this->dataset(['name' => 'po-headers']);

        $this->artisan('ods:token', [
            'app_name' => 'armors',
            '--dataset' => 'po-headers',
            '--name' => 'laptop',
        ])->assertSuccessful();

        $token = ApiToken::query()->where('app_name', 'armors')->firstOrFail();

        $this->assertSame($dataset->id, $token->dataset_id);
        $this->assertSame('laptop', $token->name);

        $this->artisan('ods:token-revoke', [
            'token' => $token->uid,
            '--reason' => 'selesai',
        ])->assertSuccessful();

        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertSame('selesai', $token->fresh()->revoked_reason);
    }

    public function test_perintah_ods_token_gagal_bila_dataset_tidak_ada(): void
    {
        $this->artisan('ods:token', [
            'app_name' => 'armors',
            '--dataset' => 'tidak-ada',
        ])->assertFailed();
    }
}