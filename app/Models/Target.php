<?php

namespace App\Models;

use App\Enums\TargetKindEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Target extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'kind', 'app_name',
        'host', 'port', 'database', 'username', 'password',
        'sslmode', 'options',
        'auto_provision_readonly', 'readonly_username', 'readonly_password',
        'is_active', 'created_by',
    ];

    /**
     * Password koneksi tidak pernah dikembalikan ke UI/API dan tidak pernah
     * masuk log. encrypted cast = terenkripsi di DB dengan APP_KEY.
     */
    protected $hidden = [
        'password', 'readonly_password',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TargetKindEnum::class,
            'password' => 'encrypted',
            'readonly_password' => 'encrypted',
            'options' => 'array',
            'auto_provision_readonly' => 'boolean',
            'is_active' => 'boolean',
            'port' => 'integer',
            'readonly_provisioned_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $target) {
            $target->uid ??= (string) Str::uuid();
        });
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }

    public function isPgA(): bool
    {
        return $this->kind === TargetKindEnum::PgA;
    }

    public function isPgB(): bool
    {
        return $this->kind === TargetKindEnum::PgB;
    }

    public function scopePgA(Builder $q): Builder
    {
        return $q->where('kind', TargetKindEnum::PgA);
    }

    public function scopePgB(Builder $q): Builder
    {
        return $q->where('kind', TargetKindEnum::PgB);
    }

    /**
     * Konfigurasi runtime untuk connection Laravel. Sengaja tidak ditaruh di
     * config/database.php karena target dibuat dari UI, bukan dari file.
     */
    public function connectionConfig(): array
    {
        $config = [
            'driver' => 'pgsql',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => $this->sslmode ?: 'prefer',
        ];

        return array_merge($config, $this->options ?? []);
    }

    /**
     * Nama connection runtime yang unik per target.
     */
    public function connectionName(): string
    {
        return 'target_'.$this->uid;
    }

    /**
     * Ringkasan aman untuk UI dan pesan error: tanpa password.
     */
    public function safeLabel(): string
    {
        return sprintf(
            '%s [%s%s %s:%d/%s]',
            $this->name,
            $this->kind->value,
            $this->app_name ? ':'.$this->app_name : '',
            $this->host,
            $this->port,
            $this->database,
        );
    }
}
