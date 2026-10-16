<?php

namespace App\Models;

use App\Enums\RunKindEnum;
use App\Enums\RunStatusEnum;
use App\Enums\RunTriggerEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Run extends Model
{
    protected $fillable = [
        'dataset_id', 'connection_id', 'target_id', 'source_target_id',
        'kind', 'trigger', 'status',
        'sql_text', 'sql_hash',
        'started_at', 'finished_at', 'duration_ms', 'row_count',
        'verification', 'error_code', 'error_message',
        'triggered_by', 'api_token_id', 'log_path',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RunKindEnum::class,
            'trigger' => RunTriggerEnum::class,
            'status' => RunStatusEnum::class,
            'verification' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'row_count' => 'integer',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function sourceTarget(): BelongsTo
    {
        return $this->belongsTo(Target::class, 'source_target_id');
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function scopeFailed(Builder $q): Builder
    {
        return $q->where('status', RunStatusEnum::Failed);
    }

    /**
     * Hasil verifikasi ✅ / ❌ untuk badge di panel Riwayat.
     */
    public function verificationBadge(): ?string
    {
        if ($this->verification === null) {
            return null;
        }

        return ($this->verification['matched'] ?? false) ? 'ok' : 'failed';
    }
}
