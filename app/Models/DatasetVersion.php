<?php

namespace App\Models;

use App\Enums\SyncModeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetVersion extends Model
{
    protected $fillable = [
        'dataset_id', 'version_no', 'sql',
        'destination_schema', 'destination_table',
        'sync_mode', 'watermark_column', 'watermark_type',
        'key_columns', 'column_overrides',
        'schedule_cron', 'reconcile_cron',
        'timeout_seconds', 'max_rows', 'batch_size',
        'change_type', 'note', 'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'sync_mode' => SyncModeEnum::class,
            'key_columns' => 'array',
            'column_overrides' => 'array',
            'version_no' => 'integer',
            'timeout_seconds' => 'integer',
            'max_rows' => 'integer',
            'batch_size' => 'integer',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
