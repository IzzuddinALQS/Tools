<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Token bearer untuk aplikasi (Tahap 2).
 *
 * Token disimpan sebagai hash SHA-256. Nilai aslinya hanya ditampilkan satu
 * kali saat pembuatan — persis seperti password.
 */
class ApiToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'app_name', 'target_id', 'dataset_id',
        'token_hash', 'rate_limit_per_minute', 'allowed_ips',
        'revoked_at', 'revoked_reason', 'created_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'rate_limit_per_minute' => 'integer',
            'allowed_ips' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $token) {
            $token->uid ??= (string) Str::uuid();
        });
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Hash token dengan cara yang sama saat mencari.
     *
     * SHA-256 tanpa salt sudah cukup di sini: token dibuat acak dengan
     * entropi tinggi, jadi tidak bisa ditebak seperti password yang
     * pendek atau bisa di-dictionary attack.
     */
    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * @return array{0:self,1:string}  token dan nilai plaintext-nya
     */
    public static function issue(array $attributes): array
    {
        $plain = Str::random(64);

        $token = static::create([
            ...$attributes,
            'token_hash' => static::hashToken($plain),
        ]);

        return [$token, $plain];
    }

    public static function findByPlainToken(string $plain): ?self
    {
        return static::query()
            ->where('token_hash', static::hashToken($plain))
            ->first();
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => Carbon::now()])->save();
    }

    public function revoke(?string $reason = null): void
    {
        $this->forceFill([
            'revoked_at' => Carbon::now(),
            'revoked_reason' => $reason,
        ])->save();
    }

    /**
     * Cek apakah IP pemanggil diizinkan.
     */
    public function allowsIp(?string $ip): bool
    {
        if ($this->allowed_ips === null || $this->allowed_ips === []) {
            return true;
        }

        return $ip !== null && in_array($ip, $this->allowed_ips, true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
