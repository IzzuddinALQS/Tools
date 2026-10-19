<?php

namespace Tests\Feature\Http;

use App\Enums\UserRoleEnum;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\SyncTestCase;
use Tests\Support\InteractsWithDatabases;

/**
 * Uji ganti password admin (B6).
 *
 * Sebelumnya tidak ada jalur apa pun untuk mengganti password, jadi
 * `ods12345` praktis permanen selama tool ini hidup.
 */
class ProfilePasswordTest extends SyncTestCase
{
    use InteractsWithDatabases;

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRoleEnum::Admin,
            'password' => 'password-yang-lama-kuat',
        ]);
    }

    public function test_wajib_login(): void
    {
        $this->post('/profile/password', [])->assertRedirect(route('login'));
    }

    public function test_admin_bisa_ganti_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/profile/password', [
                'current_password' => 'password-yang-lama-kuat',
                'password' => 'password-baru-yang-kuat',
                'password_confirmation' => 'password-baru-yang-kuat',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(
            Hash::check('password-baru-yang-kuat', $admin->fresh()->password),
            'Password baru harus aktif.',
        );

        $this->assertFalse(
            Hash::check('password-yang-lama-kuat', $admin->fresh()->password),
            'Password lama harus tidak berlaku lagi.',
        );
    }

    public function test_password_lama_salah_ditolak(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/profile/password', [
                'current_password' => 'tebakan-salah',
                'password' => 'password-baru-yang-kuat',
                'password_confirmation' => 'password-baru-yang-kuat',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(
            Hash::check('password-yang-lama-kuat', $admin->fresh()->password),
            'Password lama harus tetap berlaku kalau penggantian ditolak.',
        );
    }

    public function test_konfirmasi_salah_ditolak(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/profile/password', [
                'current_password' => 'password-yang-lama-kuat',
                'password' => 'password-baru-yang-kuat',
                'password_confirmation' => 'beda-sekali',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password-yang-lama-kuat', $admin->fresh()->password));
    }

    public function test_password_pendek_ditolak(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/profile/password', [
                'current_password' => 'password-yang-lama-kuat',
                'password' => 'pendek',
                'password_confirmation' => 'pendek',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_password_baru_tidak_boleh_sama_dengan_lama(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/profile/password', [
                'current_password' => 'password-yang-lama-kuat',
                'password' => 'password-yang-lama-kuat',
                'password_confirmation' => 'password-yang-lama-kuat',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_password_lama_tidak_boleh_dipakai_masuk_lagi(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/profile/password', [
            'current_password' => 'password-yang-lama-kuat',
            'password' => 'password-baru-yang-kuat',
            'password_confirmation' => 'password-baru-yang-kuat',
        ]);

        $this->post('/logout');

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password-yang-lama-kuat',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password-baru-yang-kuat',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($admin);
    }
}