<?php

namespace App\Models;

use App\Enums\DatasetStatusEnum;
use App\Enums\SyncModeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Dataset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'connection_id', 'target_id',
        'sql', 'identity_mode', 'destination_schema', 'destination_table',
        'column_overrides', 'sync_mode', 'watermark_column', 'watermark_type',
        'key_columns', 'schedule_cron', 'reconcile_cron',
        'timeout_seconds', 'max_rows', 'batch_size', 'auto_copy_to',
        'status', 'current_version_no', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sync_mode' => SyncModeEnum::class,
            'status' => DatasetStatusEnum::class,
            'identity_mode' => 'boolean',
            'key_columns' => 'array',
            'column_overrides' => 'array',
            'auto_copy_to' => 'array',
            'timeout_seconds' => 'integer',
            'max_rows' => 'integer',
            'batch_size' => 'integer',
            'last_success_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $dataset) {
            $dataset->uid ??= (string) Str::uuid();
        });
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DatasetVersion::class)->orderBy('version_no');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class)->latest('id');
    }

    public function scopeRunnable(Builder $q): Builder
    {
        return $q->where('status', DatasetStatusEnum::Active);
    }

    public function isIncremental(): bool
    {
        return $this->sync_mode === SyncModeEnum::Incremental;
    }

    /**
     * Nama tabel yang sepenuhnya di-quote untuk PostgreSQL.
     * Aturan "sama persis": huruf besar + quoted identifier.
     */
    public function qualifiedDestination(): string
    {
        return PgIdentifier::qualify($this->destination_schema, $this->destination_table);
    }

    /**
     * Peringatan mode incremental yang WAJIB ditampilkan di UI.
     */
    public function incrementalWarnings(): array
    {
        if (! $this->isIncremental()) {
            return [];
        }

        return [
            'Baris yang DIHAPUS di Oracle tidak terdeteksi oleh mode incremental.',
            'Dataset ini wajib disertai jadwal rekonsiliasi full berkala',
            '('.($this->reconcile_cron ?: 'belum diset').') agar hasilnya kembali identik.',
        ];
    }
}
