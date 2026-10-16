<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value untuk state global tool.
 *
 * Kill switch TIDAK disimpan di cache: harus konsisten antar beberapa worker
 * dan queue server, serta bertahan setelah restart.
 */
class Setting extends Model
{
    public const GLOBAL_PAUSE = 'global_pause';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? ($row->value ?? []) : $default;
    }

    public static function put(string $key, mixed $value, ?int $userId = null): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $userId]
        );

        Cache::forget('setting:'.$key);
    }

    /**
     * @return array{paused:bool, at:?string, by:?int, reason:?string}
     */
    public static function pauseState(): array
    {
        return static::get(static::GLOBAL_PAUSE, [
            'paused' => false, 'at' => null, 'by' => null, 'reason' => null,
        ]) ?? ['paused' => false, 'at' => null, 'by' => null, 'reason' => null];
    }

    public static function isPaused(): bool
    {
        return (bool) (static::pauseState()['paused'] ?? false);
    }
}
