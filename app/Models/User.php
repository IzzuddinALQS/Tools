<?php

namespace App\Models;

use App\Enums\UserRoleEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use Notifiable;

    /** @var array<int,string> */
    protected $fillable = [
        'name', 'email', 'password', 'role',
    ];

    /** @var array<int,string> */
    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRoleEnum::class,
        ];
    }

    /**
     * Hanya admin yang boleh membuka halaman Driver dan mengubah dataset
     * (Keamanan 3). Peran checked di middleware, bukan di view.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRoleEnum::Admin;
    }
}
