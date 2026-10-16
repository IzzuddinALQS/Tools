<?php

namespace App\Models;

use App\Enums\DriverEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Connection extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'driver',
        'host', 'port', 'service_name', 'sid', 'tns_alias', 'dsn',
        'username', 'password', 'options', 'fixture_set',
        'is_active', 'created_by',
    ];

    /**
     * Kredensial Oracle tidak pernah dikembalikan ke UI/API, tidak pernah
     * ditampilkan lagi, dan tidak pernah di-log.
     */
    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'driver' => DriverEnum::class,
            'password' => 'encrypted',
            'options' => 'array',
            'is_active' => 'boolean',
            'port' => 'integer',
            'last_tested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $connection) {
            $connection->uid ??= (string) Str::uuid();
        });
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }

    public function isMock(): bool
    {
        return $this->driver === DriverEnum::Mock;
    }

    public function isOracle(): bool
    {
        return $this->driver === DriverEnum::Oracle;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function fixtureSet(): string
    {
        return $this->fixture_set
            ?: (string) config('oracle-sync.fixtures.default_set');
    }

    /**
     * Driver yang dipakai form saat membuat koneksi baru.
     */
    public static function defaultDriver(): DriverEnum
    {
        $driver = (string) config('oracle-sync.defaults.driver', 'mock');

        return DriverEnum::tryFrom($driver) ?? DriverEnum::Mock;
    }

    /**
     * Ringkasan aman untuk ditampilkan di UI / log: tidak ada password,
     * tidak ada username lengkap.
     */
    public function safeLabel(): string
    {
        $location = $this->isMock()
            ? 'fixture:'.$this->fixtureSet()
            : $this->host.':'.$this->port;

        return sprintf('%s [%s %s]', $this->name, $this->driver->value, $location);
    }
}
