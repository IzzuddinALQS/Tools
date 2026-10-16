<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Smoke test halaman depan.
 *
 * Aplikasi adalah alat internal: '/' selalu wajib login, jadi pengunjung
 * yang belum masuk harus dilempar ke halaman login, bukan melihat dashboard.
 */
class ExampleTest extends TestCase
{
    public function test_halaman_depan_mengarah_ke_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_halaman_depan_tampil_setelah_login(): void
    {
        $user = User::factory()->create(['role' => \App\Enums\UserRoleEnum::Admin]);

        $this->actingAs($user)->get('/')->assertOk();
    }
}
